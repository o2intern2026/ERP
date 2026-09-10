<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\Charge;
use App\Modules\Billing\Models\CustomerQuote;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\ChargeEngine;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/** Blocking-bug audit 2026-09-10, Billing majors: storage-only Job rows in the unbilled pool, half-filled quote lines. */
class Audit20260910Test extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_unbilled_pool_offers_per_job_invoicing_only_where_service_charges_exist(): void
    {
        $client = $this->client(['invoice_mode' => 'per_job']);
        $finance = $this->staff('finance');
        $mixed = app(JobService::class)->create($client->id, 'loose', ['reference' => 'MIXED'])['job_id'];
        $storageOnly = app(JobService::class)->create($client->id, 'loose', ['reference' => 'STORAGE-ONLY'])['job_id'];
        $engine = app(ChargeEngine::class);
        $engine->manual($mixed, $client->id, 'WH-PUTAWAY-PLT', 2, 'putaway', null, $finance->id); // 2 × 4.50 service
        $engine->manual($mixed, $client->id, 'WH-STORAGE-PLT-WK', 4, 'storage week', null, $finance->id); // storage → weekly invoice
        $engine->manual($storageOnly, $client->id, 'WH-STORAGE-PLT-WK', 3, 'storage week', null, $finance->id);
        $serviceCents = (int) Charge::query()->where('job_id', $mixed)->whereHas('chargeCode', fn ($q) => $q->where('category', '!=', 'storage'))->sum('amount_cents');
        $this->assertGreaterThan(0, $serviceCents);

        $page = $this->actingAs($finance)->get(route('billing.unbilled'))->assertOk();
        $page->assertSee('action="'.route('billing.invoices.draft_job', $mixed).'"', false);
        $page->assertDontSee('action="'.route('billing.invoices.draft_job', $storageOnly).'"', false); // no 按此 Job 开票 on a row that can never be invoiced per Job
        $page->assertSee(__('billing.unbilled.storage_only')); // …the row says why and points at the weekly storage form instead
        $page->assertSee(__('billing.unbilled.draft_storage'));

        // Belt and braces: a direct POST on the storage-only Job is refused in Chinese and creates nothing.
        $this->actingAs($finance)->post(route('billing.invoices.draft_job', $storageOnly))->assertRedirect()->assertSessionHasErrors(['invoice' => __('billing.errors.no_unbilled_job')]);
        $this->assertSame(0, Invoice::query()->count());

        // The mixed Job drafts a service invoice; its storage stays in the pool for the weekly storage invoice.
        $this->actingAs($finance)->post(route('billing.invoices.draft_job', $mixed))->assertRedirect();
        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame(['service', $serviceCents], [$invoice->invoice_type, (int) $invoice->subtotal_cents]);
        $this->assertSame(1, Charge::query()->where('job_id', $mixed)->whereNull('invoice_line_id')->count());
    }

    public function test_quote_line_with_a_charge_code_but_no_qty_is_a_validation_error_not_a_silent_drop(): void
    {
        $client = $this->client();
        $cs = $this->staff('customer_service');
        $lines = [['charge_code' => 'TR-DELIVERY-BASE', 'qty' => 1], ['charge_code' => 'TR-TAILGATE', 'qty' => '', 'cost' => 50], ['charge_code' => '', 'qty' => '']];

        $this->actingAs($cs)->from(route('billing.quotes.create'))->post(route('billing.quotes.store'), ['client_id' => $client->id, 'stage' => 'preliminary', 'lines' => $lines])
            ->assertRedirect(route('billing.quotes.create'))->assertSessionHasErrors('lines.1.qty');
        $lines[1]['qty'] = 0;
        $this->actingAs($cs)->from(route('billing.quotes.create'))->post(route('billing.quotes.store'), ['client_id' => $client->id, 'stage' => 'preliminary', 'lines' => $lines])
            ->assertRedirect(route('billing.quotes.create'))->assertSessionHasErrors('lines.1.qty');
        $this->assertSame(0, CustomerQuote::query()->count()); // nothing half-created

        // The message is Chinese and names the field (lang/zh/validation.php attributes).
        $this->assertStringNotContainsString('required', (string) session('errors')->first('lines.1.qty'));
        $this->assertStringContainsString(trans('validation.attributes')['lines.*.qty'], (string) session('errors')->first('lines.1.qty'));

        // Empty spare rows are still ignored, and a fully filled row set goes through as before.
        $lines[1]['qty'] = 1;
        $this->actingAs($cs)->post(route('billing.quotes.store'), ['client_id' => $client->id, 'stage' => 'preliminary', 'lines' => $lines])->assertRedirect();
        $this->assertSame(2, CustomerQuote::query()->firstOrFail()->lines()->count());
    }

    public function test_quote_form_keeps_a_touched_spare_row_visible_after_a_validation_error(): void
    {
        $client = $this->client();
        $cs = $this->staff('customer_service');
        $blank = $this->actingAs($cs)->get(route('billing.quotes.create'))->assertOk()->getContent();
        $this->assertSame(3, substr_count($blank, 'class="grid quote-line" hidden')); // rows 3-5 start collapsed

        $lines = array_fill(0, 3, ['charge_code' => '', 'qty' => '']) + [3 => ['charge_code' => 'TR-TAILGATE', 'qty' => '']]; // the user opened row 3 and forgot the qty
        $page = $this->actingAs($cs)->followingRedirects()->from(route('billing.quotes.create'))->post(route('billing.quotes.store'), ['client_id' => $client->id, 'stage' => 'preliminary', 'lines' => $lines])->assertOk();
        $this->assertSame(2, substr_count($page->getContent(), 'class="grid quote-line" hidden')); // row 3 stays open with its code and the error on it
        $this->assertMatchesRegularExpression('/name="lines\[3\]\[qty\]"[^>]*\brequired\b[^>]*aria-invalid="true"/', $page->getContent()); // marked as the field to fix
        $this->assertDoesNotMatchRegularExpression('/name="lines\[0\]\[qty\]"[^>]*aria-invalid/', $page->getContent()); // untouched rows are not flagged
        $page->assertSee(trans('validation.attributes')['lines.*.qty']);
    }
}
