<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\Charge;
use App\Modules\Billing\Models\ChargeCode;
use App\Modules\Billing\Models\CreditNote;
use App\Modules\Billing\Models\CustomerQuote;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\RateCard;
use App\Modules\Billing\Models\RateItem;
use App\Modules\Billing\Services\ChargeEngine;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Billing\Services\RateService;
use App\Modules\Platform\Models\Approval;
use App\Modules\Platform\Services\ApprovalService;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * Blocking-bug audit 2026-09-10, Billing minors: credit-note 开出 gated and explained, forms that keep what was typed, percent
 * surcharges never quote as a priced-looking $0, 按账期生成草稿 defaults follow the pool.
 */
class Audit20260910MinorsTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private function issuedInvoice(int $clientId): Invoice
    {
        $finance = $this->staff('finance');
        $job = app(JobService::class)->create($clientId, 'loose')['job_id'];
        app(ChargeEngine::class)->manual($job, $clientId, 'WH-PUTAWAY-PLT', 4, 'demo', null, $finance->id);
        $invoice = app(InvoiceService::class)->draftForJob($job);
        app(InvoiceService::class)->issue($invoice);

        return $invoice->fresh();
    }

    public function test_credit_note_issue_button_follows_the_approval_and_the_form_keeps_its_values(): void
    {
        $client = $this->client();
        $invoice = $this->issuedInvoice($client->id);
        $line = $invoice->lines()->firstOrFail();
        $finance = $this->staff('finance');

        // Empty amounts: refused in Chinese, the <details> reopens with the typed reason.
        $this->actingAs($finance)->post(route('billing.invoices.credit_notes.store', $invoice), ['reason' => '客户投诉货损', 'lines' => [['invoice_line_id' => $line->id, 'amount' => '']]])
            ->assertSessionHasErrors(['reason' => __('billing.credit_notes.errors.positive_amount')])->assertSessionHasInput('reason', '客户投诉货损');
        $this->actingAs($finance)->get(route('billing.invoices.show', $invoice))->assertOk()->assertSee('<details open>', false)->assertSee('value="客户投诉货损"', false);

        $this->actingAs($finance)->post(route('billing.invoices.credit_notes.store', $invoice), ['reason' => '客户投诉货损', 'lines' => [['invoice_line_id' => $line->id, 'amount' => 5]]])->assertSessionHasNoErrors();
        $note = CreditNote::query()->firstOrFail();

        // Drafted → the 开出 button is disabled and points at the approval queue; the POST is refused in Chinese.
        $this->actingAs($finance)->get(route('billing.invoices.show', $invoice))->assertOk()
            ->assertSee(__('billing.credit_notes.pending_hint'))->assertSee(route('platform.approvals.index', ['type' => 'credit_note']))
            ->assertSee('<button type="submit" class="secondary outline" disabled>'.__('billing.credit_notes.issue'), false);
        $this->actingAs($finance)->post(route('billing.credit_notes.issue', $note))->assertSessionHasErrors(['credit_note' => __('billing.credit_notes.errors.not_approved')]);

        // Approved by a second person → badge 已批准 and an enabled button.
        app(ApprovalService::class)->approve(Approval::query()->where('type', 'credit_note')->firstOrFail(), $this->staff('admin'));
        $this->actingAs($finance)->get(route('billing.invoices.show', $invoice))->assertOk()->assertSee(__('billing.credit_notes.approved_badge'))->assertDontSee('disabled>'.__('billing.credit_notes.issue'), false);
        $this->actingAs($finance)->post(route('billing.credit_notes.issue', $note))->assertSessionHasNoErrors();
        $this->assertSame('issued', $note->fresh()->status);
    }

    public function test_rate_card_forms_keep_what_was_typed_after_a_validation_error(): void
    {
        $client = $this->client();
        $finance = $this->staff('finance');
        $code = ChargeCode::query()->where('code', 'TR-TAILGATE')->firstOrFail();

        $this->actingAs($finance)->post(route('billing.rate_cards.store'), ['client_id' => $client->id, 'name' => '', 'effective_from' => '2026-10-01'])->assertSessionHasErrors('name');
        $this->actingAs($finance)->get(route('billing.rate_cards.index'))->assertOk()->assertSee('<details open>', false)->assertSee('<option value="'.$client->id.'" selected>', false)->assertSee('value="2026-10-01"', false);

        $card = RateCard::query()->create(['client_id' => $client->id, 'name' => 'draft', 'version' => 1, 'effective_from' => today(), 'status' => 'draft']);
        $this->actingAs($finance)->post(route('billing.rate_cards.items.store', $card), ['charge_code_id' => $code->id, 'pricing_mode' => 'fixed', 'rate' => '12.50', 'min_charge' => '30', 'pallet_class' => 'oversize_high', 'zone' => 'METRO', 'threshold_json' => '{tailgate_weight_kg: 25}'])
            ->assertSessionHasErrors('threshold_json');
        $this->actingAs($finance)->get(route('billing.rate_cards.show', $card))->assertOk()->assertSee('<details open>', false)
            ->assertSee('value="12.50"', false)->assertSee('value="METRO"', false)->assertSee('<option value="oversize_high" selected>', false)->assertSee('<option value="'.$code->id.'" selected>', false)
            ->assertSee('value="{tailgate_weight_kg: 25}"', false);
        $this->assertSame(0, RateItem::query()->where('rate_card_id', $card->id)->count());
    }

    public function test_percent_surcharge_without_a_base_is_flagged_not_priced_at_zero(): void
    {
        $client = $this->client();
        $codes = ChargeCode::query()->pluck('id', 'code');
        $card = RateCard::query()->create(['client_id' => $client->id, 'name' => 'freight', 'version' => 1, 'effective_from' => today()->subDay(), 'status' => 'active']);
        RateItem::query()->create(['rate_card_id' => $card->id, 'charge_code_id' => $codes['TR-DELIVERY-BASE'], 'pricing_mode' => 'fixed', 'rate_cents' => 9500]);
        RateItem::query()->create(['rate_card_id' => $card->id, 'charge_code_id' => $codes['TR-FUEL'], 'pricing_mode' => 'percent', 'markup_percent' => 10]);
        $rates = app(RateService::class);

        $this->assertTrue($rates->price($client->id, 'TR-FUEL', 1)['is_poa']); // no base → cannot be priced
        $priced = $rates->price($client->id, 'TR-FUEL', 1, ['base_cents' => 9500]);
        $this->assertFalse($priced['is_poa']);
        $this->assertSame(950, $priced['amount_cents']);

        // The quote form: a percent line without 基数 shows 待报价; with 基数 it prices; the form remembers the value on a round trip.
        $cs = $this->staff('customer_service');
        $this->actingAs($cs)->get(route('billing.quotes.create'))->assertOk()->assertSee(__('billing.quotes.line_base'));
        $this->actingAs($cs)->post(route('billing.quotes.store'), ['client_id' => $client->id, 'stage' => 'preliminary', 'lines' => [['charge_code' => 'TR-DELIVERY-BASE', 'qty' => 1], ['charge_code' => 'TR-FUEL', 'qty' => 1]]])->assertSessionHasNoErrors();
        $quote = CustomerQuote::query()->firstOrFail();
        $this->actingAs($cs)->get(route('billing.quotes.show', $quote))->assertOk()->assertSee(__('billing.quotes.poa_flag'));
        $this->assertSame(0, $quote->lines()->where('charge_code', 'TR-FUEL')->value('amount_cents'));

        $this->actingAs($cs)->post(route('billing.quotes.store'), ['client_id' => $client->id, 'stage' => 'preliminary', 'lines' => [['charge_code' => 'TR-DELIVERY-BASE', 'qty' => 1], ['charge_code' => 'TR-FUEL', 'qty' => 1, 'base' => '95']]])->assertSessionHasNoErrors();
        $second = CustomerQuote::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame(950, $second->lines()->where('charge_code', 'TR-FUEL')->value('amount_cents'));
        $this->actingAs($cs)->get(route('billing.quotes.show', $second))->assertOk()->assertDontSee(__('billing.quotes.poa_flag'));
    }

    public function test_period_draft_defaults_follow_the_unbilled_pool(): void
    {
        $client = $this->client(['invoice_mode' => 'monthly']);
        $finance = $this->staff('finance');
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];
        $engine = app(ChargeEngine::class);
        $engine->manual($job, $client->id, 'WH-PUTAWAY-PLT', 2, 'last month', null, $finance->id);
        $engine->manual($job, $client->id, 'WH-STORAGE-PLT-WK', 3, 'storage', null, $finance->id);
        $lastMonth = today()->subMonthNoOverflow()->startOfMonth()->addDays(9);
        Charge::query()->update(['charge_date' => $lastMonth->toDateString()]);

        // Defaults: the pool's own span (last month) and 全部费用, because the pool mixes storage and service.
        $page = $this->actingAs($finance)->get(route('billing.unbilled'))->assertOk();
        $page->assertSee('name="from" value="'.$lastMonth->toDateString().'"', false)->assertSee('name="to" value="'.$lastMonth->toDateString().'"', false);
        $page->assertSee('<option value="all" selected>', false);

        // The first click with the defaults now drafts what the header showed.
        $this->actingAs($finance)->post(route('billing.invoices.draft_period'), ['client_id' => $client->id, 'from' => $lastMonth->toDateString(), 'to' => $lastMonth->toDateString(), 'scope' => 'all'])->assertSessionHasNoErrors();
        $this->assertSame(2, Invoice::query()->firstOrFail()->lines()->count());

        // Storage-only pool → 仓储费 preselected.
        $engine->manual($job, $client->id, 'WH-STORAGE-PLT-WK', 1, 'storage again', null, $finance->id);
        $this->actingAs($finance)->get(route('billing.unbilled'))->assertOk()->assertSee('<option value="storage" selected>', false);
    }
}
