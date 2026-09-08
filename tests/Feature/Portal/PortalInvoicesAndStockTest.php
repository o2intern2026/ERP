<?php

namespace Tests\Feature\Portal;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\ChargeEngine;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\MasterData\Models\Client;
use App\Support\Contracts\JobService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** ERP_PLAN §7 step 8 / §3.8 #5: a client downloads its GST-inclusive invoice PDFs and checks its own stock in the portal — never another client's. */
class PortalInvoicesAndStockTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_client_lists_and_downloads_only_its_own_issued_invoices(): void
    {
        Storage::fake('local');
        $client = $this->client(['name' => 'Portal Invoice Client']);
        $other = $this->client(['name' => 'Other Invoice Client']);
        $user = $this->clientUser($client);
        $finance = $this->staff('finance');

        $mine = $this->issuedInvoice($client, $finance->id);          // issued through the real InvoiceService: PDF stored, document client-visible
        $theirs = $this->issuedInvoice($other, $finance->id);
        $draft = $this->draftInvoice($client, $finance->id);          // Finance's working copy — not the client's business yet
        app(InvoiceService::class)->recordPayment($mine, 1000, today(), 'eft', 'RCPT-1');

        $page = $this->actingAs($user)->get(route('portal.invoices.index'))->assertOk();
        $page->assertSee($mine->invoice_no)->assertSee(__('portal.invoices.types.service'))->assertSee(__('portal.invoices.statuses.part_paid'))
            ->assertSee(Money::cents($mine->total_cents)->format())->assertSee(Money::cents($mine->total_cents - 1000)->format()) // outstanding incl. GST
            ->assertSee(route('portal.invoices.download', $mine))
            ->assertDontSee($theirs->invoice_no)->assertDontSee(route('portal.invoices.download', $theirs))
            ->assertDontSee(route('portal.invoices.download', $draft));
        $this->assertGreaterThan($mine->subtotal_cents, $mine->total_cents, 'the invoice carries GST');

        $this->actingAs($user)->get(route('portal.invoices.download', $mine))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($user)->get(route('portal.invoices.download', $theirs))->assertNotFound();
        $this->actingAs($user)->get(route('portal.invoices.download', $draft))->assertNotFound();

        // Staff are not portal users; client users never reach the Billing pages.
        $this->actingAs($finance)->get(route('portal.invoices.index'))->assertForbidden();
        $this->actingAs($user)->get('/billing/invoices')->assertForbidden();
    }

    public function test_client_sees_only_its_own_stock_with_reserved_and_available_cartons(): void
    {
        $client = $this->client();
        $other = $this->client();
        $user = $this->clientUser($client);
        $warehouse = $this->warehouse();
        ['asn' => $asn, 'lines' => $lines] = $this->stockedAsn($client, $warehouse, [
            ['mark' => 'STK-MINE', 'description' => 'Display stands', 'cartons' => 40, 'units' => [['unit_type' => 'pallet', 'carton_qty' => 20], ['unit_type' => 'pallet', 'carton_qty' => 20]]],
            ['mark' => 'STK-LOOSE', 'description' => 'Loose lamps', 'cartons' => 7, 'location_type' => 'pickface'],
        ]);
        $this->stockedAsn($other, $warehouse, [['mark' => 'STK-THEIRS', 'description' => 'Secret goods', 'cartons' => 9]]);
        // A confirmed order reserves 15 cartons of the pallet line → 40 on hand, 15 reserved, 25 available.
        $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $lines[0]->id, 'qty' => 15]]);

        $page = $this->actingAs($user)->get(route('portal.stock.index'))->assertOk();
        $page->assertSee('STK-MINE')->assertSee('Display stands')->assertSee($asn->asn_no)->assertSee('STK-LOOSE')
            ->assertSee(__('portal.stock.location_types.storage'))->assertSee(__('portal.stock.location_types.pickface'))->assertSee(__('portal.stock.conditions.good'))
            ->assertSeeInOrder(['<td class="num">2</td>', '<td class="num">40</td>', '<td class="num">15</td>', '<td class="num"><strong>25</strong></td>'], false)
            ->assertSee(__('portal.stock.totals', ['lines' => 2, 'units' => 3]))
            ->assertSeeInOrder(['<th class="num">2</th>', '<th class="num">47</th>', '<th class="num">15</th>', '<th class="num">32</th>'], false)
            ->assertDontSee('STK-THEIRS')->assertDontSee('Secret goods');

        $this->actingAs($user)->get(route('portal.stock.index', ['q' => 'LOOSE']))->assertOk()->assertSee('STK-LOOSE')->assertDontSee('STK-MINE');
        $this->actingAs($user)->get(route('portal.stock.index', ['q' => 'THEIRS']))->assertOk()->assertSee(__('portal.stock.empty'))->assertDontSee('STK-THEIRS');
        $this->actingAs($this->staff('warehouse_supervisor'))->get(route('portal.stock.index'))->assertForbidden();
        $this->actingAs($user)->get('/warehouse')->assertForbidden(); // client users never leave /portal
    }

    private function issuedInvoice(Client $client, int $financeId): Invoice
    {
        $invoices = app(InvoiceService::class);

        return $invoices->issue($this->draftInvoice($client, $financeId))->fresh();
    }

    private function draftInvoice(Client $client, int $financeId): Invoice
    {
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];
        $engine = app(ChargeEngine::class);
        $engine->manual($job, $client->id, 'WH-PUTAWAY-PLT', 2, 'demo', null, $financeId);
        $engine->manual($job, $client->id, 'WH-LABEL-IN', 10, 'demo', null, $financeId);

        return app(InvoiceService::class)->draftForJob($job);
    }
}
