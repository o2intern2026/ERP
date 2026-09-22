<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\Charge;
use App\Modules\Billing\Models\ChargeCode;
use App\Modules\Billing\Models\CreditNote;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\RateCard;
use App\Modules\Billing\Models\RateItem;
use App\Modules\Billing\Services\ChargeEngine;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\Order;
use App\Modules\Platform\Models\Approval;
use App\Modules\Platform\Models\ExceptionRecord;
use App\Modules\Warehouse\Services\AsnService;
use App\Support\Contracts\JobService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * Audit 2026-09-22 blockers A9–A11 (lead approval 2026-09-22): FIN-01 / GAP-04 the tax invoice names the seller and how to pay;
 * FIN-02 a credit note's GST comes off the outstanding balance and a line cannot be over-credited; FIN-03 (CR #132) a missing
 * rate is a $0 needs_review row that stays out of the pool and every invoice until priced — never lost, never billed at $0.
 */
class AuditBillingBlockersTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    private const CJK = '/[\x{3000}-\x{303F}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{FF00}-\x{FFEF}]/u';

    public function test_the_tax_invoice_pdf_prints_the_seller_how_to_pay_rate_and_source_documents_and_the_balance_when_part_paid(): void
    {
        Storage::fake('local');
        $client = $this->client(['payment_terms' => 'net_14', 'invoice_mode' => 'per_job']);
        $warehouse = $this->warehouse();
        $finance = $this->staff('finance');
        $engine = app(ChargeEngine::class);
        $invoices = app(InvoiceService::class);
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        $order = Order::query()->create([
            'order_no' => 'ORD-PDF-1', 'client_id' => $client->id, 'job_id' => $asn->job_id, 'order_type' => 'from_stock', 'source' => 'manual', 'consignment_mark' => 'PDF-MARK',
            'deliver_to_name' => 'Receiver', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Melbourne', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000',
            'requested_date' => today()->addDay()->toDateString(), 'operational_status' => 'confirmed',
        ]);
        $engine->applyEvent(['event_name' => 'asn.putaway_completed', 'job_id' => $asn->job_id, 'client_id' => $client->id, 'payload' => ['asn_id' => $asn->id, 'pallet_count' => 2, 'label_count' => 0, 'pallets' => []]]);
        $engine->applyEvent(['event_name' => 'outbound.packed', 'job_id' => $asn->job_id, 'client_id' => $client->id, 'payload' => ['order_id' => $order->id, 'fulfilment_id' => 5, 'is_urgent' => false, 'label_count' => 0, 'lines' => []]]);
        $invoice = $invoices->draftForJob($asn->job_id);
        $this->assertSame(2, $invoice->lines()->count()); // putaway 2 × 4.50 on the ASN, despatch 5.00 on the order

        $this->actingAs($finance)->post("/billing/invoices/{$invoice->id}/issue")->assertRedirect()->assertSessionHasNoErrors();
        $invoice->refresh();
        $html = view('billing::invoices.pdf', $invoices->pdfData($invoice))->render();

        // Seller block with the ABN, the placeholder company details from config (no .env needed on the trial server), and how to pay.
        foreach (['TAX INVOICE', 'Demo Logistics Pty Ltd', 'ABN 12 345 678 901', '1 Warehouse Road, Moorebank NSW 2170', '+61 2 0000 0000', 'accounts@example.com',
            'How to pay', 'Demo Bank', '000-000', '12345678', 'Payment reference', $invoice->invoice_no, 'Bill to', $client->name] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertMatchesRegularExpression('/Payment reference<\/td><td><strong>'.preg_quote($invoice->invoice_no, '/').'/', $html);
        // A Rate column from the charge's rate snapshot, and the source document instead of the internal charge id.
        $this->assertStringContainsString('<th class="num">Rate</th>', $html);
        $this->assertStringContainsString('$4.50', $html);   // WH-PUTAWAY-PLT rate
        $this->assertStringContainsString('ASN '.$asn->asn_no, $html);
        $this->assertStringContainsString('Order ORD-PDF-1', $html);
        $this->assertStringContainsString('Mark PDF-MARK', $html);
        foreach ($invoice->lines as $line) {
            $this->assertStringNotContainsString('#'.$line->charge_id.'<', $html);
        }
        $this->assertStringNotContainsString('Balance due', $html); // nothing paid yet
        $this->assertStringContainsString('<html lang="en">', $html);
        $this->assertStringNotContainsString('pdf.', $html);
        $this->assertDoesNotMatchRegularExpression(self::CJK, $html, 'the tax invoice must be English');
        $this->assertStringStartsWith('%PDF', $this->actingAs($finance)->get("/billing/invoices/{$invoice->id}/pdf")->assertOk()->getContent());

        // Part-paid: Paid and Balance due under the totals.
        $invoices->recordPayment($invoice, 1000, today(), 'bank', 'RCPT-9');
        $partPaid = view('billing::invoices.pdf', $invoices->pdfData($invoice->fresh()))->render();
        $this->assertStringContainsString('Paid', $partPaid);
        $this->assertStringContainsString('Balance due', $partPaid);
        $this->assertStringContainsString(Money::cents($invoice->total_cents - 1000)->format(), $partPaid);
    }

    public function test_issuing_is_refused_in_chinese_while_the_company_abn_is_blank(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $finance = $this->staff('finance');
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];
        app(ChargeEngine::class)->manual($job, $client->id, 'WH-PUTAWAY-PLT', 2, 'demo', null, $finance->id);
        $draft = app(InvoiceService::class)->draftForJob($job);

        config(['erp.company.abn' => '']); // an operator blanked COMPANY_ABN
        $this->actingAs($finance)->post("/billing/invoices/{$draft->id}/issue")->assertRedirect()->assertSessionHasErrors(['invoice' => __('billing.errors.company_abn_missing')]);
        $this->assertMatchesRegularExpression(self::CJK, __('billing.errors.company_abn_missing'));
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertStringStartsWith('DR-', $draft->fresh()->invoice_no);
        $this->assertDatabaseMissing('documents', ['type' => 'invoice']);

        config(['erp.company.abn' => '12 345 678 901']);
        $this->actingAs($finance)->post("/billing/invoices/{$draft->id}/issue")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('issued', $draft->fresh()->status);
    }

    public function test_a_credit_note_reduces_the_outstanding_balance_with_its_gst_everywhere_and_a_line_cannot_be_over_credited(): void
    {
        Storage::fake('local');
        $client = $this->client(['payment_terms' => 'eom', 'invoice_mode' => 'per_job']);
        $finance = $this->staff('finance');
        $second = $this->staff('finance');
        $portalUser = $this->clientUser($client);
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];
        $engine = app(ChargeEngine::class);
        $engine->manual($job, $client->id, 'WH-PUTAWAY-PLT', 20, 'demo', null, $finance->id); // 90.00
        $engine->manual($job, $client->id, 'WH-LABEL-IN', 100, 'demo', null, $finance->id);  // 30.00
        $invoices = app(InvoiceService::class);
        $invoice = $invoices->issue($invoices->draftForJob($job));
        $this->assertSame([12000, 1200, 13200], [$invoice->subtotal_cents, $invoice->gst_cents, $invoice->total_cents]);
        $putaway = $invoice->lines()->where('charge_code', 'WH-PUTAWAY-PLT')->sole();

        // Over the line: refused server side (the browser `max` was the only guard), in Chinese, nothing drafted.
        $this->actingAs($finance)->post("/billing/invoices/{$invoice->id}/credit-notes", ['reason' => 'too much', 'lines' => [['invoice_line_id' => $putaway->id, 'amount' => 90.01]]])
            ->assertRedirect()->assertSessionHasErrors('lines');
        $this->assertSame(0, CreditNote::query()->count());
        $this->assertStringContainsString('WH-PUTAWAY-PLT', session('errors')->first('lines'));
        $this->assertMatchesRegularExpression(self::CJK, session('errors')->first('lines'));

        // 50.00 + 5.00 GST credited on the putaway line, approved by a second person, issued.
        $this->actingAs($finance)->post("/billing/invoices/{$invoice->id}/credit-notes", ['reason' => 'damaged', 'lines' => [['invoice_line_id' => $putaway->id, 'amount' => 50]]])->assertRedirect()->assertSessionHasNoErrors();
        $note = CreditNote::query()->sole();
        $this->assertSame([5000, 500], [$note->amount_cents, $note->gst_cents]);
        $this->actingAs($second)->post('/admin/approvals/'.Approval::query()->where('type', 'credit_note')->sole()->id.'/approve', ['note' => 'ok'])->assertRedirect();
        $this->actingAs($finance)->post("/billing/credit-notes/{$note->id}/issue")->assertRedirect()->assertSessionHasNoErrors();

        // FIN-02: 13200 − (5000 + 500) everywhere — the model, the client-wide receivable, the receivables page and the client portal.
        $invoice->refresh();
        $this->assertSame(5500, $invoice->creditedCents());
        $this->assertSame(7700, $invoice->outstandingCents());
        $this->assertSame(7700, $invoices->outstandingCents($client->id));
        $this->actingAs($finance)->get('/billing/receivables')->assertOk()->assertSee($client->name)->assertSee('$77.00');
        $this->actingAs($finance)->get("/billing/invoices/{$invoice->id}")->assertOk()->assertSee('$77.00');
        $this->actingAs($portalUser)->get(route('portal.invoices.index'))->assertOk()->assertSee(__('portal.invoices.outstanding'))->assertSee('$77.00')->assertDontSee('$82.00');

        // The remaining 40.00 on that line can still be credited, but not a cent more (credit notes already on the line count, draft or issued).
        $this->actingAs($finance)->post("/billing/invoices/{$invoice->id}/credit-notes", ['reason' => 'again', 'lines' => [['invoice_line_id' => $putaway->id, 'amount' => 40.01]]])->assertRedirect()->assertSessionHasErrors('lines');
        $this->assertSame(1, CreditNote::query()->count());
        $this->actingAs($finance)->post("/billing/invoices/{$invoice->id}/credit-notes", ['reason' => 'rest', 'lines' => [['invoice_line_id' => $putaway->id, 'amount' => 40]]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, CreditNote::query()->count());

        // Paying exactly the GST-inclusive balance settles the invoice (recordPayment uses the same credited figure).
        $invoices->recordPayment($invoice, 7700, today(), 'bank', 'RCPT-2');
        $this->assertSame(['paid', 0], [$invoice->fresh()->status, $invoice->fresh()->outstandingCents()]);
    }

    public function test_a_missing_rate_is_a_needs_review_placeholder_with_the_suggested_freight_price_out_of_the_pool_priced_on_the_review_page_and_replay_safe(): void
    {
        $client = $this->client(['name' => 'Freight Missing Pty Ltd']); // the standard card has no TR-* rows (charge-codes.md §6)
        $finance = $this->staff('finance');
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];
        $engine = app(ChargeEngine::class);
        $invoices = app(InvoiceService::class);
        $quote = fn (int $shipmentId, int $version = 1) => ['event_name' => 'shipment.quote_confirmed', 'job_id' => $job, 'client_id' => $client->id, 'payload' => [
            'shipment_id' => $shipmentId, 'order_id' => null, 'quote_stage' => 'final', 'activity_version' => $version, 'carrier_cost_cents' => 10000, 'customer_price_cents' => 14500, 'zone' => '3000', 'tailgate_required' => false,
        ]];
        $byCode = fn (string $activity, string $code) => Charge::query()->where('source_activity_id', $activity)->whereHas('chargeCode', fn ($q) => $q->where('code', $code))->sole();

        $charges = $engine->applyEvent($quote(31));
        $this->assertCount(2, $charges); // TR-DELIVERY-BASE and TR-FUEL, both unpriced
        $freight = $byCode('shipment:31', 'TR-DELIVERY-BASE');
        $fuel = $byCode('shipment:31', 'TR-FUEL');
        $this->assertSame(['needs_review', 0, null, null, 'shipment', 31], [$freight->status, $freight->amount_cents, $freight->rate_item_id, $freight->rate_snapshot_cents, $freight->source_type, $freight->source_id]);
        $this->assertTrue($freight->calculation_snapshot_json['missing_rate']);
        $this->assertSame(14500, $freight->calculation_snapshot_json['suggested_cents']); // the customer freight price the event carried
        $this->assertNull($fuel->calculation_snapshot_json['suggested_cents']);         // a surcharge is never suggested the whole freight
        $this->assertSame('needs_review', $fuel->status);
        $exception = ExceptionRecord::query()->findOrFail($freight->calculation_snapshot_json['exception_id']);
        $this->assertSame(['missing_rate', 'billing', 'open', $client->id, $job, 'shipment', 31], [$exception->type, $exception->source_module, $exception->status, $exception->client_id, $exception->job_id, $exception->source_type, $exception->source_id]);
        $this->assertSame(2, ExceptionRecord::query()->where('type', 'missing_rate')->count());

        // Out of the pool and of every draft — "never $0" holds; the pool page still shows the client with its counts.
        try {
            $invoices->draftForJob($job);
            $this->fail('a $0 placeholder must not be invoiced');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(__('billing.errors.no_unbilled_job'), $e->getMessage());
        }
        $this->actingAs($finance)->get('/billing/unbilled')->assertOk()->assertSee(__('billing.unbilled.attention_title'))->assertSee('Freight Missing Pty Ltd')
            ->assertSee(__('billing.unbilled.missing_rate_count', ['n' => 2]))->assertSee(__('billing.unbilled.review_count', ['n' => 2]))
            ->assertSee(route('billing.charges.review', ['client_id' => $client->id]))->assertSee(route('platform.exceptions.index', ['type' => 'missing_rate', 'client_id' => $client->id]));

        // A priced charge on the same Job drafts as usual; the draft page warns about the two unpriced items above 开出发票 — it does not block.
        $engine->applyEvent(['event_name' => 'asn.putaway_completed', 'job_id' => $job, 'client_id' => $client->id, 'payload' => ['asn_id' => 800, 'pallet_count' => 2, 'label_count' => 0, 'pallets' => []]]);
        $engine->applyEvent(['event_name' => 'asn.putaway_completed', 'job_id' => $job, 'client_id' => $client->id, 'payload' => ['asn_id' => 801, 'pallet_count' => 1, 'label_count' => 0, 'pallets' => []]]);
        $this->actingAs($finance)->get('/billing/unbilled')->assertOk()->assertDontSee(__('billing.unbilled.attention_title'))
            ->assertSee(__('billing.unbilled.missing_rate_count', ['n' => 2]))->assertSee(__('billing.unbilled.review_count', ['n' => 2]))->assertSee(__('billing.unbilled.attention_hint'));
        $draft = $invoices->draftForJob($job);
        $this->assertSame(2, $draft->lines()->count());
        $this->assertSame(0, $draft->lines()->where('charge_code', 'like', 'TR-%')->count());
        $this->actingAs($finance)->get("/billing/invoices/{$draft->id}")->assertOk()
            ->assertSee(__('billing.invoices.review_warning_title', ['n' => 2]))->assertSee('TR-DELIVERY-BASE')->assertSee('TR-FUEL')->assertSee(__('billing.charges.missing_rate_badge'))
            ->assertSee(__('billing.invoices.review_warning_hint'))->assertSee(__('billing.invoices.issue'));

        // The review page pre-fills the suggested freight price; pricing it makes the charge billable and closes its exception.
        $this->actingAs($finance)->get('/billing/charges/review?client_id='.$client->id)->assertOk()->assertSee('TR-DELIVERY-BASE')->assertSee(__('billing.charges.missing_rate_badge'))
            ->assertSee('value="145.00"', false)->assertSee(__('billing.charges.suggested_amount', ['amount' => '$145.00']))->assertSee(__('billing.charges.no_suggested_amount'));
        $this->actingAs($finance)->post("/billing/charges/{$freight->id}/review", ['amount' => 145, 'note' => 'as quoted'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['approved', 14500], [$freight->fresh()->status, $freight->fresh()->amount_cents]);
        $this->assertSame(['resolved', $finance->id], [$exception->fresh()->status, $exception->fresh()->resolved_by]);
        $this->assertStringContainsString('as quoted', $exception->fresh()->message);
        $this->assertSame('open', ExceptionRecord::query()->findOrFail($fuel->calculation_snapshot_json['exception_id'])->status);

        // Replaying the same event version changes nothing: same key, no second row, no second exception, the priced row untouched.
        $this->assertCount(2, $engine->applyEvent($quote(31)));
        $this->assertSame(2, Charge::query()->where('source_activity_id', 'shipment:31')->count());
        $this->assertSame(['approved', 14500], [$freight->fresh()->status, $freight->fresh()->amount_cents]);
        $this->assertSame('needs_review', $fuel->fresh()->status);
        $this->assertSame(2, ExceptionRecord::query()->where('type', 'missing_rate')->count());

        // Now it drafts (the earlier draft already holds both putaways); the warning shrinks to the fuel row.
        $next = $invoices->draftForJob($job);
        $this->assertSame(['TR-DELIVERY-BASE'], $next->lines()->pluck('charge_code')->all());
        $this->assertSame(14500, $next->subtotal_cents);
        $item = fn (Charge $c) => __('billing.invoices.review_item', ['id' => $c->id, 'code' => $c->chargeCode->code, 'qty' => '1', 'job' => $c->job->job_no]);
        $this->actingAs($finance)->get("/billing/invoices/{$next->id}")->assertOk()->assertSee(__('billing.invoices.review_warning_title', ['n' => 1]))
            ->assertSee($item($fuel->fresh()))->assertDontSee($item($freight->fresh()));
    }

    public function test_adding_the_rate_and_replaying_the_event_prices_the_placeholder_in_place_and_a_newer_version_supersedes_it_without_a_twin(): void
    {
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];
        $engine = app(ChargeEngine::class);
        $quote = fn (Client $c, int $jobId, int $shipmentId, int $version = 1) => ['event_name' => 'shipment.quote_confirmed', 'job_id' => $jobId, 'client_id' => $c->id, 'payload' => [
            'shipment_id' => $shipmentId, 'order_id' => null, 'quote_stage' => 'final', 'activity_version' => $version, 'carrier_cost_cents' => 10000, 'customer_price_cents' => 14500, 'zone' => '3000', 'tailgate_required' => false,
        ]];
        $byCode = fn (string $activity, string $code) => Charge::query()->where('source_activity_id', $activity)->whereHas('chargeCode', fn ($q) => $q->where('code', $code))->sole();

        $engine->applyEvent($quote($client, $job, 41));
        $freight = $byCode('shipment:41', 'TR-DELIVERY-BASE');
        $fuel = $byCode('shipment:41', 'TR-FUEL');
        $exceptionId = (int) $freight->calculation_snapshot_json['exception_id'];

        // Finance adds the rates to the client's card and the event is replayed (cron, no signed-in user): the same rows are priced in place.
        $card = RateCard::query()->create(['client_id' => $client->id, 'name' => 'freight', 'version' => 1, 'effective_from' => today()->subDay(), 'status' => 'active']);
        RateItem::query()->create(['rate_card_id' => $card->id, 'charge_code_id' => ChargeCode::query()->where('code', 'TR-DELIVERY-BASE')->value('id'), 'pricing_mode' => 'fixed', 'rate_cents' => 15000]);
        RateItem::query()->create(['rate_card_id' => $card->id, 'charge_code_id' => ChargeCode::query()->where('code', 'TR-FUEL')->value('id'), 'pricing_mode' => 'percent', 'markup_percent' => 5]);
        $this->assertCount(2, $engine->applyEvent($quote($client, $job, 41)));
        $this->assertSame(2, Charge::query()->where('source_activity_id', 'shipment:41')->count());
        $healed = $freight->fresh();
        $this->assertSame(['pending', 15000, 15000, 'client'], [$healed->status, $healed->amount_cents, $healed->rate_snapshot_cents, $healed->calculation_snapshot_json['card']]);
        $this->assertNotNull($healed->rate_item_id);
        $this->assertArrayNotHasKey('missing_rate', $healed->calculation_snapshot_json);
        $this->assertSame($exceptionId, $healed->calculation_snapshot_json['repriced_from_missing_rate']['exception_id']);
        $this->assertSame(['pending', 725], [$fuel->fresh()->status, $fuel->fresh()->amount_cents]); // 5 % of the 145.00 customer price
        $this->assertTrue($healed->isBillable());
        $this->assertSame(0, ExceptionRecord::query()->where('type', 'missing_rate')->where('status', '!=', 'resolved')->count());
        $closed = ExceptionRecord::query()->findOrFail($exceptionId);
        $this->assertNull($closed->resolved_by); // the system closed it
        $this->assertStringContainsString(__('billing.exceptions.missing_rate_priced', ['code' => 'TR-DELIVERY-BASE', 'amount' => '$150.00']), $closed->message);
        $this->assertSame(0, Charge::query()->whereNotNull('reversal_of_charge_id')->count()); // no reversal rows: the placeholder itself became the charge

        // A newer activity version of an unpriced shipment: the $0 placeholders are marked reversed without a negative twin, their exceptions
        // closed, and the new version raises its own placeholders + exceptions (still unpriced for this client).
        $other = $this->client();
        $otherJob = app(JobService::class)->create($other->id, 'loose')['job_id'];
        $engine->applyEvent($quote($other, $otherJob, 42, 1));
        $v1 = Charge::query()->where('source_activity_id', 'shipment:42')->where('activity_version', 1)->get();
        $this->assertSame(['needs_review', 'needs_review'], $v1->pluck('status')->all());
        $engine->applyEvent($quote($other, $otherJob, 42, 2));
        $this->assertSame(['reversed', 'reversed'], $v1->map(fn (Charge $c) => $c->fresh()->status)->all());
        $this->assertSame(0, Charge::query()->whereNotNull('reversal_of_charge_id')->count());
        $this->assertSame(2, Charge::query()->where('source_activity_id', 'shipment:42')->where('activity_version', 2)->where('status', 'needs_review')->count());
        $v1Exceptions = $v1->map(fn (Charge $c) => (int) $c->calculation_snapshot_json['exception_id']);
        $this->assertSame(['resolved', 'resolved'], ExceptionRecord::query()->whereIn('id', $v1Exceptions)->pluck('status')->all());
        $this->assertSame(2, ExceptionRecord::query()->where('type', 'missing_rate')->where('client_id', $other->id)->where('status', 'open')->count());
    }
}
