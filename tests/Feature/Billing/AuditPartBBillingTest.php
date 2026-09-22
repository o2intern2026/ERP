<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\Charge;
use App\Modules\Billing\Models\ChargeCode;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\RateCard;
use App\Modules\Billing\Models\RateItem;
use App\Modules\Billing\Services\ChargeEngine;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\MasterData\Models\Client;
use App\Modules\Platform\Models\Approval;
use App\Modules\Platform\Models\Job;
use App\Support\Contracts\JobService;
use App\Support\Exceptions\RuleViolation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * Audit 2026-09-22 Part B billing items, CHANGE_REQUESTS #140 (lead approval 2026-09-22): FIN-16 an invoiced charge is reduced by a
 * credit note, never 冲销 a second time; FIN-13 payments pre-fill the balance, refuse over-payment and are voided by a negative row;
 * FIN-08 a submitted rate card is locked, the approver gets a link and a diff; FIN-09 the manual-charge form has no default code, a
 * 费用日期 and Job prefill; FIN-10 a Job on an open draft is merged into it, the header count equals the pool, charges show their invoice.
 */
class AuditPartBBillingTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private const CJK = '/[\x{3000}-\x{303F}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{FF00}-\x{FFEF}]/u';

    /** A Job with a putaway (20 × 4.50 = 90.00) and an inbound-label (100 × 0.30 = 30.00) manual charge: 120.00 + 12.00 GST = 132.00 once issued. */
    private function chargedJob(Client $client, int $by): int
    {
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];
        $engine = app(ChargeEngine::class);
        $engine->manual($job, $client->id, 'WH-PUTAWAY-PLT', 20, 'demo', null, $by);
        $engine->manual($job, $client->id, 'WH-LABEL-IN', 100, 'demo', null, $by);

        return $job;
    }

    public function test_fin16_an_invoiced_charge_offers_the_credit_note_path_instead_of_a_reversal_and_the_list_shows_its_invoice(): void
    {
        Storage::fake('local');
        $client = $this->client(['invoice_mode' => 'per_job']);
        $finance = $this->staff('finance');
        $engine = app(ChargeEngine::class);
        $invoices = app(InvoiceService::class);
        $job = $this->chargedJob($client, $finance->id);
        $invoice = $invoices->issue($invoices->draftForJob($job));
        $invoiced = Charge::query()->where('job_id', $job)->whereHas('chargeCode', fn ($q) => $q->where('code', 'WH-PUTAWAY-PLT'))->sole();
        $pooled = $engine->manual($job, $client->id, 'WH-LABEL-IN', 10, 'later', null, $finance->id); // 3.00, still in the pool
        $otherJob = app(JobService::class)->create($client->id, 'loose')['job_id'];
        $drafted = $engine->manual($otherJob, $client->id, 'WH-PUTAWAY-PLT', 1, 'drafted', null, $finance->id);
        $draft = $invoices->draftForJob($otherJob);

        // The list: 发票 column (issued number / 草稿中), 去开冲减单 for the invoiced row, 冲销 only for the pooled row, a hint on the drafted row.
        $page = $this->actingAs($finance)->get('/billing')->assertOk();
        $page->assertSee($invoice->invoice_no)->assertSee($draft->invoice_no)->assertSee(__('billing.charges.in_draft'))->assertSee(__('billing.charges.invoice'));
        $page->assertSee(route('billing.invoices.show', $invoice).'?credit_line='.$invoiced->invoice_line_id.'#credit-note')->assertSee(__('billing.charges.to_credit_note'));
        $page->assertDontSee(route('billing.charges.reverse', $invoiced))->assertDontSee(route('billing.charges.reverse', $drafted))->assertSee(route('billing.charges.reverse', $pooled));
        $page->assertSee(__('billing.charges.on_draft_hint'));

        // 冲销 posted anyway (an old tab, a hand-made request): refused in Chinese, no negative twin, nothing changes on the invoice.
        $this->actingAs($finance)->from('/billing')->post("/billing/charges/{$invoiced->id}/reverse", ['reason' => 'oops'])->assertRedirect('/billing')
            ->assertSessionHasErrors(['charge' => __('billing.charges.errors.invoiced_use_credit_note', ['id' => $invoiced->id, 'no' => $invoice->invoice_no])]);
        $this->assertMatchesRegularExpression(self::CJK, session('errors')->first('charge'));
        $this->actingAs($finance)->from('/billing')->post("/billing/charges/{$drafted->id}/reverse", ['reason' => 'oops'])->assertRedirect('/billing')
            ->assertSessionHasErrors(['charge' => __('billing.charges.errors.on_draft', ['id' => $drafted->id, 'no' => $draft->invoice_no])]);
        $this->assertSame(0, Charge::query()->whereNotNull('reversal_of_charge_id')->count());
        $this->assertSame(['invoiced', 'pending'], [$invoiced->fresh()->status, $drafted->fresh()->status]);
        $this->assertSame(13200, $invoice->fresh()->total_cents);

        // The pooled charge is still reversed the old way.
        $this->actingAs($finance)->post("/billing/charges/{$pooled->id}/reverse", ['reason' => 'wrong qty'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('reversed', $pooled->fresh()->status);

        // 去开冲减单 lands on the invoice with the form open and that line's remaining amount filled in.
        $line = $invoice->lines()->where('charge_id', $invoiced->id)->sole();
        $show = $this->actingAs($finance)->get(route('billing.invoices.show', $invoice).'?credit_line='.$line->id)->assertOk();
        $show->assertSee('<details open>', false)->assertSee(__('billing.credit_notes.preselected_hint'))->assertSee('id="credit-note"', false);
        $show->assertSee('value="90.00" autofocus data-preselected="1"', false)->assertSee(__('billing.credit_notes.remaining', ['amount' => '$90.00']));
        $this->actingAs($finance)->get(route('billing.invoices.show', $invoice))->assertOk()->assertDontSee('<details open>', false)->assertDontSee('data-preselected', false);

        // A credit note drafted from there shows up in the approval centre as a link to the invoice (FIN-08), not as "credit_note #1".
        $this->actingAs($finance)->post("/billing/invoices/{$invoice->id}/credit-notes", ['reason' => 'damaged', 'lines' => [['invoice_line_id' => $line->id, 'amount' => 90]]])->assertRedirect()->assertSessionHasNoErrors();
        $note = $invoice->creditNotes()->sole();
        $this->actingAs($this->staff('finance'))->get('/admin/approvals')->assertOk()->assertSee(route('billing.invoices.show', $invoice))
            ->assertSee(__('platform.approvals.subjects.credit_note', ['no' => $note->credit_note_no, 'invoice' => $invoice->invoice_no]))->assertDontSee('credit_note #'.$note->id);
        // After a credit note the remaining amount on that line is 0 — the pre-fill stays empty.
        $this->actingAs($finance)->get(route('billing.invoices.show', $invoice).'?credit_line='.$line->id)->assertOk()->assertSee(__('billing.credit_notes.remaining', ['amount' => '$0.00']))->assertDontSee('value="90.00"', false);
    }

    public function test_fin13_payments_prefill_the_balance_refuse_over_payment_and_are_voided_by_a_negative_row_that_recomputes_the_invoice(): void
    {
        Storage::fake('local');
        $client = $this->client(['invoice_mode' => 'per_job', 'payment_terms' => 'net_14']);
        $finance = $this->staff('finance');
        $invoices = app(InvoiceService::class);
        $invoice = $invoices->issue($invoices->draftForJob($this->chargedJob($client, $finance->id)));
        $invoice->update(['due_at' => today()->subDay()]); // past due: the overdue flag must follow the balance
        $this->assertSame(13200, $invoice->total_cents);
        $url = "/billing/invoices/{$invoice->id}";

        // The amount box carries the balance.
        $this->actingAs($finance)->get($url)->assertOk()->assertSee('name="amount" value="132.00"', false)->assertSee(__('billing.invoices.payment_amount_hint'));

        // 7561.40 typed for 756.14: refused in Chinese, nothing recorded, still 已开出.
        $this->actingAs($finance)->from($url)->post("$url/payments", ['amount' => 132.01, 'paid_at' => today()->toDateString(), 'method' => 'bank'])->assertRedirect($url)
            ->assertSessionHasErrors(['amount' => __('billing.errors.payment_exceeds_outstanding', ['amount' => '$132.01', 'outstanding' => '$132.00'])]);
        $this->assertMatchesRegularExpression(self::CJK, session('errors')->first('amount'));
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(['issued', 0], [$invoice->fresh()->status, $invoice->fresh()->paid_amount_cents]);

        // 100.00 then the rest: the box follows the balance; paid clears the overdue flag.
        $this->actingAs($finance)->post("$url/payments", ['amount' => 100, 'paid_at' => today()->toDateString(), 'method' => 'bank', 'reference' => 'RCPT-1'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['part_paid', 10000, true], [$invoice->fresh()->status, $invoice->fresh()->paid_amount_cents, $invoice->fresh()->is_overdue]);
        $this->actingAs($finance)->get($url)->assertOk()->assertSee('name="amount" value="32.00"', false);
        $this->actingAs($finance)->post("$url/payments", ['amount' => 32, 'paid_at' => today()->toDateString(), 'method' => 'bank', 'reference' => 'RCPT-2'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['paid', 13200, false], [$invoice->fresh()->status, $invoice->fresh()->paid_amount_cents, $invoice->fresh()->is_overdue]);
        $this->actingAs($finance)->get($url)->assertOk()->assertDontSee(__('billing.invoices.record_payment'))->assertSee(__('billing.invoices.void_payment'));
        $first = Payment::query()->where('reference', 'RCPT-1')->sole();
        $second = Payment::query()->where('reference', 'RCPT-2')->sole();

        // 作废收款: reason required; a negative twin row, the original kept; paid / status / overdue recomputed; the receivable reappears.
        $this->actingAs($finance)->from($url)->post("/billing/payments/{$first->id}/void", [])->assertRedirect($url)->assertSessionHasErrors('reason');
        $this->actingAs($finance)->from($url)->post("/billing/payments/{$first->id}/void", ['reason' => '打错发票'])->assertRedirect($url)->assertSessionHasNoErrors();
        $twin = Payment::query()->where('void_of_payment_id', $first->id)->sole();
        $this->assertSame([-10000, 'bank', 'RCPT-1', '打错发票', $finance->id, today()->toDateString()], [$twin->amount_cents, $twin->method, $twin->reference, $twin->note, $twin->recorded_by, $twin->paid_at->toDateString()]);
        $this->assertSame(3, Payment::query()->count()); // nothing deleted
        $this->assertSame(10000, $first->fresh()->amount_cents);
        $this->assertSame(['part_paid', 3200, 10000, true], [$invoice->fresh()->status, $invoice->fresh()->paid_amount_cents, $invoice->fresh()->outstandingCents(), $invoice->fresh()->is_overdue]);
        $this->assertSame(10000, $invoices->outstandingCents($client->id));
        $this->actingAs($finance)->get('/billing/receivables')->assertOk()->assertSee($client->name)->assertSee('$100.00');
        $page = $this->actingAs($finance)->get($url)->assertOk();
        $page->assertSee(__('billing.invoices.voided_badge'))->assertSee(__('billing.invoices.void_of', ['id' => $first->id]))->assertSee('打错发票')->assertSee('-$100.00');
        $page->assertDontSee(route('billing.payments.void', $first))->assertDontSee(route('billing.payments.void', $twin))->assertSee(route('billing.payments.void', $second));
        $page->assertSee('name="amount" value="100.00"', false); // the form is back with the new balance
        $this->actingAs($this->clientUser($client))->get(route('portal.invoices.index'))->assertOk()->assertSee('$100.00');

        // Voiding twice, or voiding the void row: refused in Chinese.
        $this->actingAs($finance)->from($url)->post("/billing/payments/{$first->id}/void", ['reason' => 'again'])->assertRedirect($url)
            ->assertSessionHasErrors(['payment' => __('billing.errors.payment_already_voided', ['id' => $first->id])]);
        $this->actingAs($finance)->from($url)->post("/billing/payments/{$twin->id}/void", ['reason' => 'undo'])->assertRedirect($url)
            ->assertSessionHasErrors(['payment' => __('billing.errors.payment_void_row', ['id' => $twin->id])]);
        $this->assertMatchesRegularExpression(self::CJK, session('errors')->first('payment'));
        $this->assertSame(3, Payment::query()->count());

        // Voiding the last receipt too: back to 已开出 with nothing paid; the service path works without a signed-in user (recorded_by given).
        $invoices->voidPayment($second, 'bank bounced', $finance->id);
        $this->assertSame(['issued', 0, 13200, true], [$invoice->fresh()->status, $invoice->fresh()->paid_amount_cents, $invoice->fresh()->outstandingCents(), $invoice->fresh()->is_overdue]);
        $this->assertNull($invoice->fresh()->paid_at);
        $this->actingAs($finance)->post("$url/payments", ['amount' => 132, 'paid_at' => today()->toDateString(), 'method' => 'bank'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['paid', 13200], [$invoice->fresh()->status, $invoice->fresh()->paid_amount_cents]);
    }

    public function test_fin08_a_submitted_rate_card_is_locked_until_decided_the_approver_gets_a_link_and_the_draft_shows_its_diff(): void
    {
        $this->client(); // seeds the standard card
        $finance = $this->staff('finance');
        $second = $this->staff('finance');
        $standard = RateCard::query()->where('is_standard', true)->where('status', 'active')->sole();
        $this->actingAs($finance)->post("/billing/rate-cards/{$standard->id}/new-version", ['effective_from' => today()->toDateString(), 'notes' => 'putaway to 5.00'])->assertRedirect();
        $v2 = RateCard::query()->where('is_standard', true)->where('version', 2)->sole();
        $url = route('billing.rate_cards.show', $v2);
        $itemOf = fn (RateCard $card, string $code) => RateItem::query()->where('rate_card_id', $card->id)->whereHas('chargeCode', fn ($q) => $q->where('code', $code))->firstOrFail();

        // An untouched copy: the diff says so, every row is "same" — which also pins that the copy keeps its thresholds as data (before this fix
        // newVersion pushed the raw JSON through the array cast and every copied version stored them double-encoded, read back as a string).
        $this->actingAs($finance)->get($url)->assertOk()->assertSee(__('billing.rate_cards.diff_none', ['version' => 1]))->assertSee('data-diff="same"', false)->assertDontSee('data-diff="changed"', false)->assertDontSee('id="rate-diff-only"', false);
        $this->assertSame(22500, $itemOf($v2, 'TR-CARTAGE-20')->threshold('max_gross_weight_kg'));
        $this->assertSame(['max_gross_weight_kg' => 22500], json_decode((string) DB::table('rate_items')->where('id', $itemOf($v2, 'TR-CARTAGE-20')->id)->value('threshold_json'), true));
        // A row a previous copy already broke (double-encoded on the server) reads as data and is written back clean.
        $legacy = $itemOf($v2, 'WH-DEVAN-40-LOOSE'); // seeded with {"max_line_count": 20}, so the healed value equals v1's and the row stays "same"
        DB::table('rate_items')->where('id', $legacy->id)->update(['threshold_json' => json_encode(json_encode(['max_line_count' => 20]))]);
        $this->assertSame(20, $legacy->fresh()->threshold('max_line_count'));
        $legacy->fresh()->update(['notes' => 'touched', 'threshold_json' => $legacy->fresh()->threshold_json]);
        $this->assertSame(['max_line_count' => 20], json_decode((string) DB::table('rate_items')->where('id', $legacy->id)->value('threshold_json'), true));

        // Change a rate, add a row, drop a row (the draft is still editable).
        $putaway = $itemOf($v2, 'WH-PUTAWAY-PLT');
        $this->actingAs($finance)->post("/billing/rate-items/{$putaway->id}", ['rate' => 5.00, 'min_charge' => 20])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($finance)->post("/billing/rate-cards/{$v2->id}/items", ['charge_code_id' => ChargeCode::query()->where('code', 'TR-WAITING')->value('id'), 'rate' => 90])->assertRedirect()->assertSessionHasNoErrors();
        $itemOf($v2, 'WH-LABEL-IN')->delete();
        $page = $this->actingAs($finance)->get($url)->assertOk();
        $page->assertSee(__('billing.rate_cards.diff_title', ['version' => 1]))->assertSee(__('billing.rate_cards.diff_counts', ['added' => 1, 'changed' => 1, 'removed' => 1]))->assertSee('id="rate-diff-only"', false)->assertSee(__('billing.rate_cards.diff_only'));
        $page->assertSee('<s class="text-muted">$4.50</s> → <strong>$5.00</strong>', false)->assertSee('<s class="text-muted">—</s> → <strong>$20.00</strong>', false); // rate and minimum: old → new
        $page->assertSee('data-diff="changed"', false)->assertSee('data-diff="added"', false)->assertSee(__('billing.rate_cards.diff_added'))->assertSee(__('billing.rate_cards.diff_changed'));
        $page->assertSee(__('billing.rate_cards.diff_removed_title', ['version' => 1]))->assertSee('data-diff="removed"', false)->assertSee('WH-LABEL-IN')->assertSee(__('billing.rate_cards.diff_removed'));
        $page->assertSee(route('billing.rate_items.update', $putaway))->assertDontSee(__('billing.rate_cards.locked_hint'));

        // Submitted for approval: items are frozen — update and add refused in Chinese, the forms gone, the hint shown.
        $this->actingAs($finance)->post("/billing/rate-cards/{$v2->id}/request-activation")->assertRedirect()->assertSessionHasNoErrors();
        $locked = __('billing.rate_cards.errors.locked_for_approval', ['version' => 2]);
        $this->assertMatchesRegularExpression(self::CJK, $locked);
        $this->actingAs($finance)->from($url)->post("/billing/rate-items/{$putaway->id}", ['rate' => 999])->assertRedirect($url)->assertSessionHasErrors(['item' => $locked]);
        $this->assertSame([500, 2000], [$putaway->fresh()->rate_cents, $putaway->fresh()->min_charge_cents]);
        $this->actingAs($finance)->from($url)->post("/billing/rate-cards/{$v2->id}/items", ['charge_code_id' => ChargeCode::query()->where('code', 'WH-LABEL-IN')->value('id'), 'rate' => 0.01])->assertRedirect($url)->assertSessionHasErrors(['item' => $locked]);
        $this->assertSame(35, $v2->items()->count()); // 34 copied + TR-WAITING − WH-LABEL-IN … + nothing
        $page = $this->actingAs($finance)->get($url)->assertOk();
        $page->assertSee(__('billing.rate_cards.locked_hint'))->assertSee(__('billing.rate_cards.locked_badge'))->assertDontSee(route('billing.rate_items.update', $putaway))->assertDontSee(__('billing.rate_cards.add_item'));
        $page->assertSee('<s class="text-muted">$4.50</s> → <strong>$5.00</strong>', false); // the approver still sees the diff

        // The approval centre links to the card instead of printing "rate_card #N".
        $approval = Approval::query()->where('type', 'rate_card_change')->where('subject_id', $v2->id)->sole();
        $this->actingAs($second)->get('/admin/approvals')->assertOk()->assertSee($url)->assertSee(__('platform.approvals.subjects.rate_card', ['name' => $v2->name, 'version' => 2]))->assertDontSee('rate_card #'.$v2->id);

        // Approved: still locked (what was approved is what goes live), then activation works.
        $this->actingAs($second)->post("/admin/approvals/{$approval->id}/approve", ['note' => 'ok'])->assertRedirect();
        $this->actingAs($finance)->from($url)->post("/billing/rate-items/{$putaway->id}", ['rate' => 999])->assertRedirect($url)->assertSessionHasErrors(['item' => $locked]);
        $this->assertSame(500, $putaway->fresh()->rate_cents);
        $this->actingAs($finance)->post("/billing/rate-cards/{$v2->id}/activate")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['active', 'superseded'], [$v2->fresh()->status, $standard->fresh()->status]);

        // A rejected request unlocks the draft again; the next draft compares against the now-active v2.
        $this->actingAs($finance)->post("/billing/rate-cards/{$v2->id}/new-version", ['effective_from' => today()->toDateString()])->assertRedirect();
        $v3 = RateCard::query()->where('is_standard', true)->where('version', 3)->sole();
        $v3Putaway = $itemOf($v3, 'WH-PUTAWAY-PLT');
        $this->actingAs($finance)->post("/billing/rate-cards/{$v3->id}/request-activation")->assertRedirect();
        $this->actingAs($finance)->post("/billing/rate-items/{$v3Putaway->id}", ['rate' => 6])->assertSessionHasErrors('item');
        $this->actingAs($second)->post('/admin/approvals/'.Approval::query()->where('subject_id', $v3->id)->where('status', 'pending')->sole()->id.'/reject', ['note' => 'no'])->assertRedirect();
        $this->actingAs($finance)->post("/billing/rate-items/{$v3Putaway->id}", ['rate' => 6])->assertSessionHasNoErrors();
        $this->assertSame(600, $v3Putaway->fresh()->rate_cents);
        $this->actingAs($finance)->get(route('billing.rate_cards.show', $v3))->assertOk()->assertSee(__('billing.rate_cards.diff_title', ['version' => 2]))
            ->assertSee('<s class="text-muted">$5.00</s> → <strong>$6.00</strong>', false)->assertSee(__('billing.rate_cards.diff_counts', ['added' => 0, 'changed' => 1, 'removed' => 0]));
    }

    public function test_fin09_the_manual_charge_form_has_no_default_code_dates_the_fee_and_is_prefilled_from_the_job_page(): void
    {
        $client = $this->client(['name' => 'Prefill Pty Ltd']);
        $other = $this->client(['name' => 'Other Pty Ltd']);
        $finance = $this->staff('finance');
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];
        $otherJob = app(JobService::class)->create($other->id, 'loose')['job_id'];
        $jobNo = fn (int $id) => Job::query()->findOrFail($id)->job_no;

        // Blank first option, a 费用日期 field defaulting to today and capped at today, every Job listed.
        $page = $this->actingAs($finance)->get('/billing/charges/manual')->assertOk();
        $page->assertSee('<select name="charge_code" required><option value="">'.__('billing.charges.select_code').'</option>', false);
        $page->assertSee('<input type="hidden" name="charge_date" value="'.today()->toDateString().'">', false)->assertSee('max="'.today()->toDateString().'"', false)->assertSee(__('billing.charges.charge_date'));
        $page->assertSee($jobNo($job))->assertSee($jobNo($otherJob))->assertDontSee(__('billing.charges.manual_all_jobs'));

        // No code → validation error, nothing posted (the alphabetically first code is no longer the silent default).
        $this->actingAs($finance)->post('/billing/charges/manual', ['job_id' => $job, 'charge_code' => '', 'qty' => 1, 'reason' => 'forgot the code'])->assertSessionHasErrors('charge_code');
        $this->assertSame(0, Charge::query()->count());
        // A future date is refused; yesterday is kept as charge_date; no date means today.
        $this->actingAs($finance)->post('/billing/charges/manual', ['job_id' => $job, 'charge_code' => 'WH-PUTAWAY-PLT', 'qty' => 1, 'reason' => 'x', 'charge_date' => today()->addDay()->toDateString()])->assertSessionHasErrors('charge_date');
        $this->assertSame(0, Charge::query()->count());
        $this->actingAs($finance)->post('/billing/charges/manual', ['job_id' => $job, 'charge_code' => 'WH-PUTAWAY-PLT', 'qty' => 2, 'reason' => 'September labour', 'charge_date' => today()->subDay()->toDateString()])->assertRedirect('/billing')->assertSessionHasNoErrors();
        $dated = Charge::query()->sole();
        $this->assertSame([today()->subDay()->toDateString(), 900, 'pending', true], [$dated->charge_date->toDateString(), $dated->amount_cents, $dated->status, $dated->is_manual]);
        $this->actingAs($finance)->post('/billing/charges/manual', ['job_id' => $job, 'charge_code' => 'WH-PUTAWAY-PLT', 'qty' => 1, 'reason' => 'today'])->assertRedirect('/billing')->assertSessionHasNoErrors();
        $this->assertSame(today()->toDateString(), Charge::query()->latest('id')->first()->charge_date->toDateString());

        // From the Job page: ?job_id= pre-selects the Job, ?client_id= narrows the list to that client.
        $page = $this->actingAs($finance)->get(route('billing.charges.manual', ['job_id' => $job, 'client_id' => $client->id]))->assertOk();
        $page->assertSee('<option value="'.$job.'" selected>', false)->assertSee($jobNo($job))->assertDontSee($jobNo($otherJob));
        $page->assertSee(__('billing.charges.manual_job_filter', ['name' => 'Prefill Pty Ltd']))->assertSee(__('billing.charges.manual_all_jobs'));

        // The Job page carries the 手工加费 link for admin / finance (also on a Job with no charge yet), not for customer service.
        $link = route('billing.charges.manual', ['job_id' => $otherJob, 'client_id' => $other->id]);
        $this->actingAs($finance)->get("/jobs/{$otherJob}")->assertOk()->assertSee(__('billing.job_panel.title'))->assertSee(__('billing.job_panel.manual_charge'))->assertSee($link);
        $this->actingAs($this->staff('customer_service'))->get("/jobs/{$otherJob}")->assertOk()->assertDontSee($link)->assertDontSee(__('billing.job_panel.manual_charge'));
        $this->actingAs($this->staff('admin'))->get("/jobs/{$job}")->assertOk()->assertSee(route('billing.charges.manual', ['job_id' => $job, 'client_id' => $client->id]))->assertSee('WH-PUTAWAY-PLT');
    }

    public function test_fin10_a_job_on_an_open_draft_is_merged_into_it_the_header_count_equals_the_pool_and_charges_show_their_invoice(): void
    {
        Storage::fake('local');
        $client = $this->client(['invoice_mode' => 'per_job']);
        $finance = $this->staff('finance');
        $engine = app(ChargeEngine::class);
        $invoices = app(InvoiceService::class);
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];
        $jobNo = Job::query()->findOrFail($job)->job_no;
        $engine->manual($job, $client->id, 'WH-PUTAWAY-PLT', 2, 'first order', null, $finance->id);          // 9.00
        $draft = $invoices->draftForJob($job);                                                                // the auto-draft of the first final quote
        $this->assertSame([1, 990], [$draft->lines()->count(), $draft->total_cents]);
        $later = $engine->manual($job, $client->id, 'WH-LABEL-IN', 10, 'second order', null, $finance->id);  // 3.00 — arrives after the draft
        $storage = $engine->manual($job, $client->id, 'WH-STORAGE-PLT-WK', 1, 'week', null, $finance->id);   // storage: never on a Job invoice
        $headerCounts = fn (int $pending, int $drafted) => __('billing.charges.counts', ['pending' => $pending, 'drafted' => $drafted, 'needs_review' => 0, 'invoiced' => 0, 'reversed' => 0]);

        // The pool row says 已有草稿 + 并入该草稿 instead of 按此 Job 开票; the header 待开票 equals the pool's rows (2: label + storage), the drafted row counted apart.
        $pool = $this->actingAs($finance)->get('/billing/unbilled')->assertOk();
        $pool->assertSee(__('billing.unbilled.has_draft', ['no' => $draft->invoice_no, 'amount' => '$9.90']))->assertSee(__('billing.unbilled.append_to_draft'))->assertSee(__('billing.unbilled.has_draft_hint'));
        $pool->assertSee(route('billing.invoices.append_job', [$draft, $job]))->assertDontSee('/billing/invoices/job/'.$job)->assertSee('1 '.__('billing.unbilled.lines'));
        $this->assertSame(2, Charge::query()->whereIn('status', ['pending', 'approved'])->whereNull('invoice_line_id')->count());
        $list = $this->actingAs($finance)->get('/billing')->assertOk();
        $list->assertSee($headerCounts(2, 1))->assertSee($draft->invoice_no)->assertSee(__('billing.charges.in_draft'))->assertSee(route('billing.invoices.index', ['status' => 'draft']));

        // The draft page: the corrected hint and the Job's new charge with 并入本草稿.
        $show = $this->actingAs($finance)->get("/billing/invoices/{$draft->id}")->assertOk();
        $show->assertSee(__('billing.invoices.draft_hint'))->assertSee(__('billing.invoices.new_charges_for_job', ['job' => $jobNo, 'n' => 1, 'amount' => '$3.00']))->assertSee(__('billing.invoices.append_to_draft'));

        // 并入: the label charge joins the draft, totals recomputed, storage stays in the pool, the pool row is storage-only now.
        $this->actingAs($finance)->post(route('billing.invoices.append_job', [$draft, $job]))->assertRedirect("/billing/invoices/{$draft->id}")->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('billing.invoices.appended', ['n' => 1, 'no' => $draft->invoice_no]));
        $draft->refresh();
        $this->assertSame([2, 1200, 120, 1320], [$draft->lines()->count(), $draft->subtotal_cents, $draft->gst_cents, $draft->total_cents]);
        $this->assertSame(['WH-PUTAWAY-PLT', 'WH-LABEL-IN'], $draft->lines()->orderBy('id')->pluck('charge_code')->all());
        $this->assertNotNull($later->fresh()->invoice_line_id);
        $this->assertNull($storage->fresh()->invoice_line_id);
        $this->assertSame([$job], $draft->jobs()->pluck('jobs.id')->all());
        $this->actingAs($finance)->get('/billing/unbilled')->assertOk()->assertSee(__('billing.unbilled.storage_only'))->assertDontSee(__('billing.unbilled.append_to_draft'))->assertDontSee(__('billing.unbilled.draft_job'));
        $this->actingAs($finance)->get('/billing')->assertOk()->assertSee($headerCounts(1, 2));
        $this->actingAs($finance)->get("/billing/invoices/{$draft->id}")->assertOk()->assertDontSee(route('billing.invoices.append_job', [$draft, $job]))->assertDontSee(__('billing.invoices.new_charges_for_job', ['job' => $jobNo, 'n' => 1, 'amount' => '$3.00']));

        // Nothing left to merge → the Job's message; a second Job of another client → refused; an issued invoice → refused; a storage draft → refused.
        $this->actingAs($finance)->from('/billing/unbilled')->post(route('billing.invoices.append_job', [$draft, $job]))->assertRedirect('/billing/unbilled')->assertSessionHasErrors(['invoice' => __('billing.errors.no_unbilled_job')]);
        $other = $this->client();
        $otherJob = app(JobService::class)->create($other->id, 'loose')['job_id'];
        $engine->manual($otherJob, $other->id, 'WH-PUTAWAY-PLT', 1, 'x', null, $finance->id);
        $this->actingAs($finance)->from('/billing/unbilled')->post(route('billing.invoices.append_job', [$draft, $otherJob]))->assertRedirect('/billing/unbilled')->assertSessionHasErrors(['invoice' => __('billing.errors.append_other_client')]);
        $this->assertSame(2, $draft->lines()->count());
        $issued = $invoices->issue($draft);
        $engine->manual($job, $client->id, 'WH-LABEL-IN', 1, 'after issue', null, $finance->id);
        $this->actingAs($finance)->from('/billing/unbilled')->post(route('billing.invoices.append_job', [$issued, $job]))->assertRedirect('/billing/unbilled')->assertSessionHasErrors(['invoice' => __('billing.errors.only_drafts_append', ['no' => $issued->invoice_no])]);
        $this->assertMatchesRegularExpression(self::CJK, session('errors')->first('invoice'));
        $storageDraft = $invoices->draftStorageWeek($client->id, today());
        try {
            $invoices->appendJobCharges($storageDraft, $job);
            $this->fail('a storage draft must not take service charges');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(__('billing.errors.append_storage_draft'), RuleViolation::display($e));
        }
        // Issued: the pool offers 按此 Job 开票 again (no open draft), the list shows the invoice number for the invoiced rows.
        $this->actingAs($finance)->get('/billing/unbilled')->assertOk()->assertSee(route('billing.invoices.draft_job', $job))->assertDontSee(__('billing.unbilled.has_draft_hint'));
        $this->actingAs($finance)->get('/billing')->assertOk()->assertSee($issued->invoice_no)->assertSee(__('billing.charges.counts', ['pending' => 2, 'drafted' => 1, 'needs_review' => 0, 'invoiced' => 2, 'reversed' => 0]));
    }
}
