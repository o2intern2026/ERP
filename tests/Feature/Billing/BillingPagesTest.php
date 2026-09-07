<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\Charge;
use App\Modules\Billing\Models\CreditNote;
use App\Modules\Billing\Models\CustomerQuote;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\RateCard;
use App\Modules\Billing\Models\RateItem;
use App\Modules\Billing\Services\ChargeEngine;
use App\Modules\Platform\Models\Approval;
use App\Support\Contracts\JobService;
use App\Support\Contracts\RateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/** FIN-3 / FIN-5 / FIN-6 / FIN-8 / PLT-7 through the pages (§6.8 #5 #6 #7 #10 #11 #12). */
class BillingPagesTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private function chargedJob(int $clientId, string $type = 'container'): int
    {
        $job = app(JobService::class)->create($clientId, $type)['job_id'];
        app(ChargeEngine::class)->applyEvent(['event_name' => 'task.completed', 'job_id' => $job, 'client_id' => $clientId, 'payload' => ['task_id' => 900 + $job, 'task_type' => 'devanning', 'billable_qty' => 1, 'container' => ['size' => '40', 'unpack_mode' => 'pallet']]]);
        app(ChargeEngine::class)->applyEvent(['event_name' => 'asn.putaway_completed', 'job_id' => $job, 'client_id' => $clientId, 'payload' => ['asn_id' => 800 + $job, 'pallet_count' => 2, 'label_count' => 2, 'pallets' => [['pallet_source' => 'client_own'], ['pallet_source' => 'client_own']]]]);

        return $job;
    }

    public function test_per_job_invoice_from_the_unbilled_pool_to_pdf_payment_and_receivables(): void
    {
        Storage::fake('local');
        $client = $this->client(['payment_terms' => 'net_14', 'invoice_mode' => 'per_job']);
        $job = $this->chargedJob($client->id);
        $finance = $this->staff('finance');

        $this->actingAs($finance)->get('/billing')->assertOk()->assertSee('WH-DEVAN-40-PLT')->assertSee(__('billing.title'));
        $this->actingAs($finance)->get('/billing/unbilled')->assertOk()->assertSee($client->name)->assertSee('289.60'); // 280 + 9 + 0.60
        $this->actingAs($finance)->post("/billing/invoices/job/{$job}")->assertRedirect();

        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame(['draft', 28960, 2896, 31856], [$invoice->status, $invoice->subtotal_cents, $invoice->gst_cents, $invoice->total_cents]);
        $this->assertSame(0, Charge::query()->where('job_id', $job)->whereNull('invoice_line_id')->count()); // out of the pool
        $this->actingAs($finance)->get('/billing/unbilled')->assertOk()->assertSee(__('billing.unbilled.empty'));

        $this->actingAs($finance)->get("/billing/invoices/{$invoice->id}")->assertOk()->assertSee(__('billing.invoices.issue'));
        $this->actingAs($finance)->post("/billing/invoices/{$invoice->id}/issue")->assertRedirect();
        $invoice->refresh();
        $this->assertMatchesRegularExpression('/^INV-\d{6}-0001$/', $invoice->invoice_no);
        $this->assertSame('issued', $invoice->status);
        $this->assertSame(today()->addDays(14)->toDateString(), $invoice->due_at->toDateString()); // net_14 (§6.8 #12)
        $this->assertSame($client->name, $invoice->bill_to_name);
        $this->assertSame(3, Charge::query()->where('job_id', $job)->where('status', 'invoiced')->count());
        $this->assertDatabaseHas('documents', ['id' => $invoice->pdf_document_id, 'type' => 'invoice', 'client_visible' => 1, 'client_id' => $client->id]);

        $pdf = $this->actingAs($finance)->get("/billing/invoices/{$invoice->id}/pdf");
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent()); // §6.8 #6

        $this->actingAs($finance)->post("/billing/invoices/{$invoice->id}/payments", ['amount' => 100, 'paid_at' => today()->toDateString(), 'method' => 'bank', 'reference' => 'RCPT1'])->assertRedirect();
        $this->assertSame(['part_paid', 10000], [$invoice->fresh()->status, $invoice->fresh()->paid_amount_cents]);
        $this->actingAs($finance)->get('/billing/receivables')->assertOk()->assertSee($client->name)->assertSee('218.56'); // §6.8 #7
        $this->actingAs($finance)->post("/billing/invoices/{$invoice->id}/payments", ['amount' => 218.56, 'paid_at' => today()->toDateString(), 'method' => 'bank'])->assertRedirect();
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->actingAs($finance)->get('/billing/receivables')->assertOk()->assertSee(__('billing.receivables.empty'));

        $this->actingAs($this->staff('warehouse_operator'))->get('/billing')->assertForbidden();
        $this->actingAs($this->clientUser($client))->get('/billing/invoices')->assertForbidden();
    }

    public function test_monthly_client_consolidates_jobs_and_credit_notes_need_a_second_person(): void
    {
        Storage::fake('local');
        $client = $this->client(['payment_terms' => 'eom', 'invoice_mode' => 'monthly']);
        $jobA = $this->chargedJob($client->id);
        $jobB = $this->chargedJob($client->id, 'loose');
        $finance = $this->staff('finance');
        $second = $this->staff('finance');

        $this->actingAs($finance)->post('/billing/invoices/monthly', ['client_id' => $client->id, 'from' => today()->startOfMonth()->toDateString(), 'to' => today()->endOfMonth()->toDateString()])->assertRedirect();
        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame('monthly', $invoice->invoice_type);
        $this->assertSame(2, $invoice->jobs()->count()); // §6.8 #11: grouped by Job
        $this->assertSame(6, $invoice->lines()->count());
        $this->actingAs($finance)->post("/billing/invoices/{$invoice->id}/issue")->assertRedirect();
        $this->assertSame(today()->endOfMonth()->toDateString(), $invoice->fresh()->due_at->toDateString()); // eom

        // Credit note: drafted by one finance user, approved by another, then issued; outstanding falls.
        $line = $invoice->lines()->first();
        $this->actingAs($finance)->post("/billing/invoices/{$invoice->id}/credit-notes", ['reason' => 'damaged carton', 'lines' => [['invoice_line_id' => $line->id, 'amount' => 50]]])->assertRedirect();
        $note = CreditNote::query()->firstOrFail();
        $this->assertSame(['draft', 5000, 500], [$note->status, $note->amount_cents, $note->gst_cents]);
        $this->actingAs($finance)->post("/billing/credit-notes/{$note->id}/issue")->assertSessionHasErrors('credit_note'); // not approved yet
        $approval = Approval::query()->where('type', 'credit_note')->firstOrFail();
        $this->actingAs($finance)->post("/admin/approvals/{$approval->id}/approve")->assertSessionHasErrors('approval'); // same person
        $this->actingAs($second)->post("/admin/approvals/{$approval->id}/approve", ['note' => 'ok'])->assertRedirect();
        $this->actingAs($finance)->post("/billing/credit-notes/{$note->id}/issue")->assertRedirect();
        $this->assertMatchesRegularExpression('/^CN-\d{6}-0001$/', $note->fresh()->credit_note_no);
        $this->assertSame($invoice->fresh()->total_cents - 5000, $invoice->fresh()->outstandingCents());
    }

    public function test_rate_card_versions_are_approval_gated_and_history_keeps_its_snapshot(): void
    {
        $client = $this->client();
        $job = $this->chargedJob($client->id);
        $before = Charge::query()->whereHas('chargeCode', fn ($q) => $q->where('code', 'WH-PUTAWAY-PLT'))->firstOrFail();
        $finance = $this->staff('finance');
        $second = $this->staff('finance');
        $standard = RateCard::query()->where('is_standard', true)->firstOrFail();

        $this->actingAs($finance)->get('/billing/rate-cards')->assertOk()->assertSee($standard->name);
        $this->actingAs($finance)->get("/billing/rate-cards/{$standard->id}")->assertOk()->assertSee('WH-PUTAWAY-PLT');
        $this->actingAs($finance)->post("/billing/rate-cards/{$standard->id}/new-version", ['effective_from' => today()->toDateString(), 'notes' => 'putaway to 5.00'])->assertRedirect();
        $v2 = RateCard::query()->where('is_standard', true)->where('version', 2)->firstOrFail();
        $this->assertSame(['draft', 34], [$v2->status, $v2->items()->count()]);

        $item = RateItem::query()->where('rate_card_id', $v2->id)->whereHas('chargeCode', fn ($q) => $q->where('code', 'WH-PUTAWAY-PLT'))->firstOrFail();
        $this->actingAs($finance)->post("/billing/rate-items/{$item->id}", ['rate' => 5.00])->assertSessionHasNoErrors();
        $this->assertSame(500, $item->fresh()->rate_cents);
        $standardItem = RateItem::query()->where('rate_card_id', $standard->id)->whereHas('chargeCode', fn ($q) => $q->where('code', 'WH-PUTAWAY-PLT'))->firstOrFail();
        $this->actingAs($finance)->post("/billing/rate-items/{$standardItem->id}", ['rate' => 9.99])->assertSessionHasErrors('item'); // active cards are immutable

        $this->actingAs($finance)->post("/billing/rate-cards/{$v2->id}/activate")->assertSessionHasErrors('card'); // no approval yet
        $this->actingAs($finance)->post("/billing/rate-cards/{$v2->id}/request-activation")->assertRedirect();
        $approval = Approval::query()->where('type', 'rate_card_change')->where('subject_id', $v2->id)->firstOrFail();
        $this->actingAs($second)->post("/admin/approvals/{$approval->id}/approve")->assertRedirect();
        $this->actingAs($finance)->post("/billing/rate-cards/{$v2->id}/activate")->assertSessionHasNoErrors();

        $this->assertSame('active', $v2->fresh()->status);
        $this->assertSame('superseded', $standard->fresh()->status);
        $this->assertSame($v2->id, $client->fresh()->standard_rate_card_id); // clients follow the standard card
        $this->assertSame(500, app(RateService::class)->price($client->id, 'WH-PUTAWAY-PLT', 1)['amount_cents']); // new price
        $this->assertSame(450, $before->fresh()->rate_snapshot_cents); // §6.8 #5 #10: history unchanged
        $this->assertSame(900, $before->fresh()->amount_cents);
    }

    public function test_manual_charges_review_queue_quotes_and_charge_codes_pages(): void
    {
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];
        $finance = $this->staff('finance');
        $cs = $this->staff('customer_service');

        $this->actingAs($finance)->get('/billing/charges/manual')->assertOk();
        $this->actingAs($finance)->post('/billing/charges/manual', ['job_id' => $job, 'charge_code' => 'VAS-PALLET-PURCHASE-NONSTD', 'qty' => 2, 'reason' => 'two odd pallets'])->assertRedirect('/billing');
        $this->assertDatabaseHas('charges', ['job_id' => $job, 'is_manual' => 1, 'manual_reason' => 'two odd pallets', 'amount_cents' => 3000, 'status' => 'pending']);
        $this->actingAs($finance)->post('/billing/charges/manual', ['job_id' => $job, 'charge_code' => 'TR-WAITING', 'qty' => 1.5, 'reason' => 'driver waited'])->assertRedirect('/billing');
        $waiting = Charge::query()->whereHas('chargeCode', fn ($q) => $q->where('code', 'TR-WAITING'))->firstOrFail();
        $this->assertSame('needs_review', $waiting->status); // no rate anywhere → review queue, never $0 silently

        $this->actingAs($finance)->get('/billing/charges/review')->assertOk()->assertSee('TR-WAITING');
        $this->actingAs($finance)->post("/billing/charges/{$waiting->id}/review", ['amount' => 90, 'note' => 'agreed with client'])->assertRedirect();
        $this->assertSame(['approved', 9000], [$waiting->fresh()->status, $waiting->fresh()->amount_cents]);

        $this->actingAs($cs)->get('/billing/quotes/create')->assertOk();
        $this->actingAs($cs)->post('/billing/quotes', ['client_id' => $client->id, 'stage' => 'preliminary', 'lines' => [['charge_code' => 'WH-DEVAN-20-LOOSE', 'qty' => 1], ['charge_code' => 'WH-PICK-CTN-22-45', 'qty' => 10, 'weight_kg' => 30], ['charge_code' => 'WH-DEVAN-20-MIXED', 'qty' => 1], ['charge_code' => '', 'qty' => '']]])->assertRedirect();
        $quote = CustomerQuote::query()->firstOrFail();
        $this->assertSame(40000 + 3500, $quote->subtotal_cents);
        $this->assertSame(3, $quote->lines()->count());
        $this->actingAs($cs)->get("/billing/quotes/{$quote->id}")->assertOk()->assertSee($quote->quote_no)->assertSee(__('billing.quotes.poa_flag'));
        $this->actingAs($cs)->post("/billing/quotes/{$quote->id}/status", ['status' => 'accepted'])->assertRedirect();
        $this->assertSame('accepted', $quote->fresh()->status);
        $this->actingAs($cs)->get('/billing/invoices')->assertForbidden(); // customer service quotes only

        $this->actingAs($finance)->get('/billing/charge-codes')->assertOk()->assertSee('WH-STORAGE-PLT-WK')->assertSee('snapshot.weekly');
        $this->actingAs($finance)->get("/jobs/{$job}")->assertOk()->assertSee(__('billing.job_panel.title'))->assertSee('VAS-PALLET-PURCHASE-NONSTD');
    }
}
