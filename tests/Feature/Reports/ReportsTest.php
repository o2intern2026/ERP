<?php

namespace Tests\Feature\Reports;

use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderCreationService;
use App\Modules\Orders\Services\OrderStatusService;
use App\Modules\Platform\Services\OutboxDispatcher;
use App\Modules\Reports\Http\ReportValidation;
use App\Modules\Reports\Services\ReportPeriod;
use App\Modules\Reports\Services\ReportService;
use App\Modules\Warehouse\Services\OutboundService;
use App\Support\Contracts\ExceptionService;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * A21 (ERP_PLAN §2.5 #1, #5): the boss report shows volume, on-time rate and margin to admin / finance only; the client report
 * holds only that client's rows and never a cost or margin column — as a portal page for client users, as a client filter for staff,
 * and as CSV exports.
 */
class ReportsTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    private Client $alpha;

    private Client $bravo;

    public function test_boss_report_shows_volume_on_time_rate_and_margin_to_admin_and_finance_only(): void
    {
        $this->scenario();
        $report = app(ReportService::class)->boss(ReportPeriod::currentMonth());

        $byStatus = collect($report['orders_by_status'])->pluck('orders', 'status');
        $this->assertSame([3, 1, 1], [$byStatus[__('orders.statuses.operational.delivered')], $byStatus[__('orders.statuses.operational.received')], $byStatus[__('orders.statuses.operational.cancelled')]]);
        $byClient = collect($report['orders_by_client'])->keyBy('client');
        $this->assertSame(['orders' => 3, 'cartons' => 13, 'delivered' => 2], collect($byClient['Alpha Pty Ltd'])->only(['orders', 'cartons', 'delivered'])->all());
        $this->assertSame(['orders' => 2, 'delivered' => 1, 'cancelled' => 1], collect($byClient['Bravo Co'])->only(['orders', 'delivered', 'cancelled'])->all());
        $onTime = collect($report['on_time'])->keyBy('client');
        $this->assertSame(['delivered' => 2, 'on_time' => 1, 'late' => 1, 'on_time_rate' => 0.5, 'overdue_open' => 1], collect($onTime['Alpha Pty Ltd'])->except(['client_id', 'client'])->all());
        $this->assertSame(['delivered' => 1, 'on_time' => 1, 'late' => 0, 'on_time_rate' => 1.0, 'overdue_open' => 0], collect($onTime['Bravo Co'])->except(['client_id', 'client'])->all());
        $this->assertSame([['asns' => 1, 'cartons' => 10, 'pallets' => 0]], collect($report['inbound'])->map(fn ($r) => collect($r)->only(['asns', 'cartons', 'pallets'])->all())->all());
        $this->assertSame(['packed_batches' => 1, 'packages' => 2, 'dispatched_batches' => 1, 'dispatched_pallets' => 0, 'dispatched_packages' => 2], collect($report['outbound'][0])->except(['client_id', 'client'])->all());
        $financials = collect($report['financials'])->keyBy('client');
        $this->assertSame(['jobs' => 3, 'estimated_revenue_cents' => 100000, 'actual_revenue_cents' => 50000, 'estimated_cost_cents' => 40000, 'actual_cost_cents' => 20000, 'margin_cents' => 90000, 'estimated_jobs' => 2], collect($financials['Alpha Pty Ltd'])->except(['client_id', 'client'])->all());
        $this->assertSame(15000, $financials['Bravo Co']['margin_cents']);
        $exceptions = collect($report['exceptions'])->pluck('open', 'type');
        $this->assertSame([1, 1, 1], [$exceptions[__('reports.exception_types.delivery_failed')], $exceptions[__('reports.exception_types.missing_rate')], $exceptions[__('reports.exception_types.pick_short')]]);

        foreach (['admin', 'finance'] as $role) {
            $this->actingAs($this->staff($role))->get(route('reports.index'))->assertOk()
                ->assertSee('Alpha Pty Ltd')->assertSee('Bravo Co')
                ->assertSee(__('reports.columns.margin_cents'))->assertSee(__('reports.columns.estimated_cost_cents'))
                ->assertSee('$900.00')->assertSee('$150.00')->assertSee('$1,050.00') // margins and their total
                ->assertSee('50.0%')->assertSee('100.0%')
                ->assertSee(__('reports.exception_types.missing_rate'))->assertSee(__('reports.exception_types.pick_short'));
        }
        // A period without activity leaves the period tables empty; open exceptions and overdue orders are current state, not period figures. An inverted range is rejected.
        $this->actingAs($this->staff('admin'))->get(route('reports.index', ['from' => '2020-01-01', 'to' => '2020-01-31']))->assertOk()->assertSee(__('reports.empty'));
        $past = app(ReportService::class)->boss(ReportPeriod::fromInput(['from' => '2020-01-01', 'to' => '2020-01-31']));
        $this->assertSame([[], [], [], []], [$past['orders_by_client'], $past['inbound'], $past['outbound'], $past['financials']]);
        $this->assertSame(0, collect($past['orders_by_status'])->sum('orders'));
        $this->assertSame([['delivered' => 0, 'overdue_open' => 1]], collect($past['on_time'])->map(fn ($r) => collect($r)->only(['delivered', 'overdue_open'])->all())->all());
        $this->assertNotEmpty($past['exceptions']);
        // 2026-09-10 rule (每一处报错都用中文): the reversed range is refused with the Chinese sentence, and that is what the page shows.
        $reversed = ReportValidation::messages()['to.after_or_equal'];
        $this->actingAs($this->staff('admin'))->from(route('reports.index'))->get(route('reports.index', ['from' => '2026-02-10', 'to' => '2026-02-01']))
            ->assertRedirect(route('reports.index'))->assertSessionHasErrors(['to' => $reversed]);
        $this->actingAs($this->staff('admin'))->get(route('reports.index'))->assertOk()->assertSee($reversed)->assertDontSee('must be a date after or equal');

        // Not for other staff, never for client users (ClientScope keeps them inside /portal).
        $this->actingAs($this->staff('customer_service'))->get(route('reports.index'))->assertForbidden();
        $this->actingAs($this->staff('warehouse_supervisor'))->get(route('reports.export', ['table' => 'financials']))->assertForbidden();
        $this->actingAs($this->clientUser($this->alpha))->get(route('reports.index'))->assertForbidden();
        $this->actingAs($this->clientUser($this->alpha))->get(route('reports.client', ['client_id' => $this->bravo->id]))->assertForbidden();

        // CSV export of a boss table carries the margin column.
        $csv = $this->actingAs($this->staff('finance'))->get(route('reports.export', ['table' => 'financials']))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment; filename=report-financials-', (string) $csv->headers->get('content-disposition'));
        $content = $csv->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString(__('reports.columns.margin_cents'), $content);
        $this->assertStringContainsString('Alpha Pty Ltd', $content);
        $this->assertStringContainsString('900.00', $content);
        $this->assertStringContainsString(__('reports.total'), $content);
        $this->actingAs($this->staff('admin'))->get(route('reports.export', ['table' => 'nope']))->assertNotFound();
    }

    public function test_client_user_sees_only_its_own_rows_and_never_cost_or_margin(): void
    {
        $this->scenario();
        $user = $this->clientUser($this->alpha);

        $page = $this->actingAs($user)->get(route('portal.reports.index'))->assertOk();
        $page->assertSee('Alpha Pty Ltd')->assertSee('$1,000.00')->assertSee('$500.00')->assertSee('50.0%')->assertSee(__('reports.exception_types.delivery_failed'));
        $page->assertDontSee('Bravo Co')->assertDontSee('100.0%')->assertDontSee(__('reports.exception_types.pick_short'))->assertDontSee(__('reports.exception_types.missing_rate'))->assertDontSee(__('reports.exception_types.manual_transport'));
        $page->assertDontSee(__('reports.columns.margin_cents'))->assertDontSee(__('reports.columns.estimated_cost_cents'))->assertDontSee(__('reports.columns.actual_cost_cents'))->assertDontSee(__('reports.columns.estimated_jobs'));
        $page->assertDontSee('$900.00')->assertDontSee('$400.00')->assertDontSee('$200.00')->assertDontSee('$150.00');

        // The service never even selects cost columns for the client view.
        $rows = app(ReportService::class)->client($this->alpha->id, ReportPeriod::currentMonth());
        $this->assertSame(['client_id', 'client', 'jobs', 'estimated_revenue_cents', 'actual_revenue_cents'], array_keys($rows['financials'][0]));
        $this->assertCount(1, $rows['orders_by_client']);
        $this->assertSame([__('reports.exception_types.delivery_failed')], collect($rows['exceptions'])->pluck('type')->all());

        // Exports: only this client's rows, no margin header.
        $csv = $this->actingAs($user)->get(route('portal.reports.export', ['table' => 'financials']))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->streamedContent();
        $this->assertStringContainsString('Alpha Pty Ltd', $csv);
        $this->assertStringNotContainsString('Bravo Co', $csv);
        $this->assertStringNotContainsString(__('reports.columns.margin_cents'), $csv);
        $this->assertStringNotContainsString('900.00', $csv);
        $orders = $this->actingAs($user)->get(route('portal.reports.export', ['table' => 'orders_by_client']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Alpha Pty Ltd', $orders);
        $this->assertStringNotContainsString('Bravo Co', $orders);
        $this->actingAs($user)->get(route('portal.reports.export', ['table' => 'nope']))->assertNotFound();
        $this->actingAs($this->staff('customer_service'))->get(route('portal.reports.index'))->assertForbidden(); // staff use /reports/client
    }

    public function test_staff_client_view_is_the_clients_report_behind_a_client_filter(): void
    {
        $this->scenario();
        $cs = $this->staff('customer_service');

        $this->actingAs($cs)->get(route('reports.client'))->assertOk()->assertSee(__('reports.client.pick_client'))->assertDontSee('>'.__('reports.columns.margin_cents').'<', false);
        $page = $this->actingAs($cs)->get(route('reports.client', ['client_id' => $this->alpha->id]))->assertOk();
        $page->assertSee('Alpha Pty Ltd')->assertSee('$1,000.00')->assertSee(__('reports.exception_types.delivery_failed'));
        // Staff-facing hints may name the hidden columns; the table itself must not carry them.
        $page->assertDontSee(__('reports.exception_types.pick_short'))->assertDontSee('>'.__('reports.columns.margin_cents').'<', false)->assertDontSee('>'.__('reports.columns.actual_cost_cents').'<', false)->assertDontSee('$900.00');

        $csv = $this->actingAs($cs)->get(route('reports.client.export', ['table' => 'orders_by_client', 'client_id' => $this->alpha->id]))->assertOk()->streamedContent();
        $this->assertStringContainsString('Alpha Pty Ltd', $csv);
        $this->assertStringNotContainsString('Bravo Co', $csv);
        $this->actingAs($cs)->get(route('reports.client.export', ['table' => 'orders_by_client']))->assertSessionHasErrors('client_id');
        $this->actingAs($this->staff('warehouse_operator'))->get(route('reports.client', ['client_id' => $this->alpha->id]))->assertForbidden();
    }

    /** Two clients: Alpha with a real stock → pick → pack → dispatch → delivered order (on time), a late delivery and an overdue open order; Bravo with one on-time delivery and a cancellation. */
    private function scenario(): void
    {
        $this->alpha = $this->client(['name' => 'Alpha Pty Ltd', 'code' => 'ALPHA']);
        $this->bravo = $this->client(['name' => 'Bravo Co', 'code' => 'BRAVO']);
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');

        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($this->alpha, $warehouse, [['mark' => 'RPT1', 'cartons' => 10, 'weight_kg' => 100]]);
        $a1 = $this->confirmedOrder($this->alpha, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 6]]);
        $fulfilment = $a1->fulfilments()->sole();
        $outbound = app(OutboundService::class);
        $task = $outbound->releaseWave($warehouse->id, ['order_ids' => [$a1->id]], $operator->id)['tasks']->sole();
        foreach ($task->lines as $line) {
            $outbound->confirmPick($line, $line->required_qty, $operator->id);
        }
        app(OutboxDispatcher::class)->dispatchDue();
        $outbound->pack($fulfilment->id, [['package_type' => 'carton', 'weight_kg' => 12], ['package_type' => 'carton', 'weight_kg' => 8]], $operator->id);
        app(OutboxDispatcher::class)->dispatchDue();
        $outbound->dispatch($fulfilment->id, 0, 'carrier', null, $operator->id);
        app(OutboxDispatcher::class)->dispatchDue();
        $this->assertSame('dispatched', $a1->fresh()->operational_status);
        app(OrderStatusService::class)->transitionOperational($a1, 'delivered'); // requested tomorrow → on time

        $a2 = $this->order($this->alpha, ['requested_date' => today()->subDay()->toDateString(), 'lines' => [['description_en' => 'Late goods', 'package_type' => 'carton', 'carton_qty' => 4]]]);
        $this->walk($a2, ['confirmed', 'allocated', 'picking', 'packed', 'dispatched', 'delivered']); // requested yesterday → late
        $a3 = $this->order($this->alpha, ['requested_date' => today()->subDay()->toDateString()]);           // still received → overdue open
        $b1 = $this->order($this->bravo, ['requested_date' => today()->addDays(2)->toDateString()]);
        $this->walk($b1, ['confirmed', 'allocated', 'picking', 'packed', 'dispatched', 'delivered']);
        $b2 = $this->order($this->bravo);
        $this->walk($b2, ['cancelled']);

        // Cached Job money (kept by Billing / Transport consumers in production; set directly as scaffolding). Margin rule = JobService::summarize.
        DB::table('jobs')->where('id', $asn->job_id)->update(['estimated_revenue_cents' => 100000, 'estimated_cost_cents' => 40000]);
        DB::table('jobs')->where('id', $a2->job_id)->update(['actual_revenue_cents' => 50000, 'actual_cost_cents' => 20000, 'cost_status' => 'confirmed']);
        DB::table('jobs')->where('id', $b1->job_id)->update(['estimated_revenue_cents' => 20000, 'estimated_cost_cents' => 5000]);

        $exceptions = app(ExceptionService::class);
        $exceptions->raise('delivery_failed', 'transport', ['client_id' => $this->alpha->id, 'job_id' => $a2->job_id, 'order_id' => $a2->id, 'message' => 'Receiver closed']);
        $exceptions->raise('missing_rate', 'billing', ['client_id' => $this->alpha->id, 'job_id' => $asn->job_id, 'message' => 'No rate for VAS-X']);
        $exceptions->raise('pick_short', 'warehouse', ['client_id' => $this->bravo->id, 'job_id' => $b1->job_id, 'order_id' => $b1->id, 'message' => 'Short 2']);
    }

    private function order(Client $client, array $overrides = []): Order
    {
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];

        return app(OrderCreationService::class)->create(array_replace([
            'client_id' => $client->id, 'job_id' => $job, 'order_type' => 'from_stock', 'external_ref' => 'RPT-'.uniqid(),
            'deliver_to_name' => 'Receiver', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Melbourne', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000',
            'deliver_to_address_type' => 'business', 'requested_date' => today()->addDay()->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_en' => 'Goods', 'package_type' => 'carton', 'carton_qty' => 3]],
        ], $overrides), null, 'manual');
    }

    private function walk(Order $order, array $statuses): void
    {
        foreach ($statuses as $status) {
            app(OrderStatusService::class)->transitionOperational($order, $status);
        }
    }
}
