<?php

namespace Tests\Feature\Reports;

use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderCreationService;
use App\Modules\Orders\Services\OrderStatusService;
use App\Modules\Reports\Mail\ClientReportMail;
use App\Modules\Reports\Services\ReportPeriod;
use App\Modules\Reports\Services\ReportService;
use App\Support\Contracts\JobService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * A22 (PLT-5): `reports:client-weekly` mails every active client with an address its own A21 client view for last week — one mail per
 * client, nothing for clients without an address, never another client's rows, never cost / margin; re-runs are idempotent.
 */
class ClientReportMailTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_weekly_command_mails_each_client_with_an_address_only_its_own_figures(): void
    {
        Mail::fake();
        $alpha = $this->client(['name' => 'Alpha Pty Ltd', 'code' => 'ALPHA', 'billing_email' => 'accounts@alpha.test', 'contact_email' => 'ops@alpha.test']);
        $bravo = $this->client(['name' => 'Bravo Co', 'code' => 'BRAVO', 'billing_email' => null, 'contact_email' => 'hello@bravo.test']); // falls back to contact_email
        $charlie = $this->client(['name' => 'Charlie Ltd', 'code' => 'CHARL', 'billing_email' => null, 'contact_email' => null]);          // no address → skipped
        $delta = $this->client(['name' => 'Delta Old', 'code' => 'DELTA', 'status' => 'inactive', 'billing_email' => 'x@delta.test']);      // inactive → never mailed
        $week = ReportPeriod::lastWeek();
        $midWeek = $week->from->addDays(2)->setTime(10, 0);

        $a1 = $this->order($alpha, ['requested_date' => $week->to->toDateString()]);
        $a2 = $this->order($alpha);
        $b1 = $this->order($bravo);
        $c1 = $this->order($charlie);
        foreach (['confirmed', 'allocated', 'picking', 'packed', 'dispatched', 'delivered'] as $status) {
            app(OrderStatusService::class)->transitionOperational($a1, $status);
        }
        DB::table('orders')->whereIn('id', [$a1->id, $a2->id, $b1->id, $c1->id])->update(['created_at' => $midWeek]);
        DB::table('order_events')->where('order_id', $a1->id)->update(['created_at' => $midWeek]); // delivered inside last week, on time
        DB::table('jobs')->where('id', $a1->job_id)->update(['estimated_revenue_cents' => 123400, 'estimated_cost_cents' => 55500, 'created_at' => $midWeek]);
        $this->order($alpha); // this week: outside the reported period

        $this->artisan('reports:client-weekly')->assertSuccessful();

        Mail::assertSent(ClientReportMail::class, 2);
        Mail::assertSent(ClientReportMail::class, function (ClientReportMail $mail) use ($week): bool {
            if (! $mail->hasTo('accounts@alpha.test')) {
                return false;
            }
            $html = $mail->render();
            $this->assertSame('weekly', $mail->frequency);
            $this->assertSame($week->label(), $mail->period->label());
            $this->assertSame([['client' => 'Alpha Pty Ltd', 'orders' => 2]], collect($mail->report['orders_by_client'])->map(fn ($r) => collect($r)->only(['client', 'orders'])->all())->all());
            $this->assertSame(['delivered' => 1, 'on_time' => 1], collect($mail->report['on_time'][0])->only(['delivered', 'on_time'])->all());
            $this->assertSame(['client_id', 'client', 'jobs', 'estimated_revenue_cents', 'actual_revenue_cents'], array_keys($mail->report['financials'][0]));
            $this->assertCount(count(ReportService::TABLES), $mail->attachments());
            $this->assertStringContainsString('Alpha Pty Ltd', $html);
            $this->assertStringContainsString('$1,234.00', $html);
            $this->assertStringContainsString($week->from->toDateString(), $html);
            $this->assertStringNotContainsString('Bravo Co', $html);
            $this->assertStringNotContainsString(__('reports.columns.margin_cents'), $html);
            $this->assertStringNotContainsString(__('reports.columns.estimated_cost_cents'), $html);
            $this->assertStringNotContainsString('555.00', $html);

            return true;
        });
        Mail::assertSent(ClientReportMail::class, fn (ClientReportMail $mail) => $mail->hasTo('hello@bravo.test') && $mail->client->is($bravo) && ! str_contains($mail->render(), 'Alpha Pty Ltd'));
        Mail::assertNotSent(ClientReportMail::class, fn (ClientReportMail $mail) => $mail->hasTo('x@delta.test') || $mail->hasTo('ops@alpha.test'));

        $this->assertDatabaseHas('report_deliveries', ['client_id' => $alpha->id, 'frequency' => 'weekly', 'status' => 'sent', 'email' => 'accounts@alpha.test', 'period_start' => $week->from->toDateString(), 'period_end' => $week->to->toDateString()]);
        $this->assertDatabaseHas('report_deliveries', ['client_id' => $bravo->id, 'frequency' => 'weekly', 'status' => 'sent', 'email' => 'hello@bravo.test']);
        $this->assertDatabaseHas('report_deliveries', ['client_id' => $charlie->id, 'frequency' => 'weekly', 'status' => 'skipped', 'error' => 'no_address', 'email' => null]);
        $this->assertDatabaseMissing('report_deliveries', ['client_id' => $delta->id]);

        // Idempotent: a second run (cron retry) mails nothing new and records nothing new; --force re-sends one client.
        $this->artisan('reports:client-weekly')->assertSuccessful();
        Mail::assertSent(ClientReportMail::class, 2);
        $this->assertSame(3, DB::table('report_deliveries')->count());
        $this->artisan('reports:client-weekly', ['--force' => true, '--client' => $alpha->id])->assertSuccessful();
        Mail::assertSent(ClientReportMail::class, 3);
        $this->assertSame(2, DB::table('report_deliveries')->where('client_id', $alpha->id)->where('status', 'sent')->count());

        // --week re-runs a past week: nothing happened then, but the client still gets its (empty) report.
        $this->artisan('reports:client-weekly', ['--week' => '2025-01-15', '--client' => $bravo->id])->assertSuccessful();
        Mail::assertSent(ClientReportMail::class, fn (ClientReportMail $mail) => $mail->hasTo('hello@bravo.test') && $mail->period->from->toDateString() === '2025-01-13' && $mail->period->to->toDateString() === '2025-01-19');
    }

    public function test_monthly_command_covers_last_month_and_the_schedule_runs_both_in_melbourne_time(): void
    {
        Mail::fake();
        $alpha = $this->client(['name' => 'Alpha Pty Ltd', 'billing_email' => 'accounts@alpha.test']);
        $this->client(['name' => 'Nobody', 'billing_email' => null, 'contact_email' => null]);
        $month = ReportPeriod::lastMonth();
        $order = $this->order($alpha);
        DB::table('orders')->where('id', $order->id)->update(['created_at' => $month->from->addDays(5)->setTime(9, 0)]);

        $this->artisan('reports:client-monthly')->assertSuccessful();
        Mail::assertSent(ClientReportMail::class, 1);
        Mail::assertSent(ClientReportMail::class, fn (ClientReportMail $mail) => $mail->hasTo('accounts@alpha.test') && $mail->frequency === 'monthly'
            && $mail->period->from->toDateString() === $month->from->toDateString() && $mail->period->to->toDateString() === $month->to->toDateString()
            && $mail->report['orders_by_client'][0]['orders'] === 1);
        $this->assertDatabaseHas('report_deliveries', ['client_id' => $alpha->id, 'frequency' => 'monthly', 'status' => 'sent', 'period_start' => $month->from->toDateString()]);

        $events = collect(app(Schedule::class)->events())->mapWithKeys(fn ($e) => [str_contains($e->command, 'reports:client-weekly') ? 'weekly' : (str_contains($e->command, 'reports:client-monthly') ? 'monthly' : $e->command) => [$e->expression, $e->timezone]]);
        $this->assertSame(['0 7 * * 1', 'Australia/Melbourne'], $events['weekly']);  // Monday 07:00 Melbourne
        $this->assertSame(['30 7 1 * *', 'Australia/Melbourne'], $events['monthly']); // 1st of the month 07:30 Melbourne
    }

    private function order(Client $client, array $overrides = []): Order
    {
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];

        return app(OrderCreationService::class)->create(array_replace([
            'client_id' => $client->id, 'job_id' => $job, 'order_type' => 'from_stock', 'external_ref' => 'MAIL-'.uniqid(),
            'deliver_to_name' => 'Receiver', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Melbourne', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000',
            'deliver_to_address_type' => 'business', 'requested_date' => today()->addDay()->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_en' => 'Goods', 'package_type' => 'carton', 'carton_qty' => 3]],
        ], $overrides), null, 'manual');
    }
}
