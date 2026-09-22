<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\Charge;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\ChargeEngine;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\MasterData\Models\Client;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * CR #142 lead decisions 3 and 4 (2026-09-22). (3) 开出发票 is BLOCKED while the draft's Job(s) / period hold unresolved needs_review
 * charges or open missing-rate exceptions (CR #132 made it a warning) — unless Finance ticks 仍然开票(未定价费用留到下期), which is
 * recorded on the invoice (notes + activity log) and shown on its page. (4) A charge line can be taken out of a DRAFT one by one: the
 * charge returns to the unbilled pool, totals are recomputed, an empty draft stays with a hint and cannot be issued; refused on an issued invoice.
 */
class LeadDecisions142BillingTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private const CJK = '/[\x{3000}-\x{303F}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{FF00}-\x{FFEF}]/u';

    /** A Job with two priced manual charges (putaway 90.00, labels 30.00) and, when asked, an unpriced TR-DELIVERY-BASE + TR-FUEL pair (the standard card has no TR-* rows). */
    private function jobWithCharges(Client $client, int $by, bool $withUnpriced): int
    {
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];
        $engine = app(ChargeEngine::class);
        $engine->manual($job, $client->id, 'WH-PUTAWAY-PLT', 20, 'demo', null, $by);
        $engine->manual($job, $client->id, 'WH-LABEL-IN', 100, 'demo', null, $by);
        if ($withUnpriced) {
            $engine->applyEvent(['event_name' => 'shipment.quote_confirmed', 'job_id' => $job, 'client_id' => $client->id, 'payload' => [
                'shipment_id' => 71, 'order_id' => null, 'quote_stage' => 'final', 'activity_version' => 1, 'carrier_cost_cents' => 10000, 'customer_price_cents' => 14500, 'zone' => '3000', 'tailgate_required' => false,
            ]]);
        }

        return $job;
    }

    public function test_issuing_is_blocked_while_unpriced_items_sit_in_scope_and_the_recorded_override_lets_it_through(): void
    {
        Storage::fake('local');
        $client = $this->client(['invoice_mode' => 'per_job']);
        $finance = $this->staff('finance');
        $job = $this->jobWithCharges($client, $finance->id, true);
        $draft = app(InvoiceService::class)->draftForJob($job);
        $this->assertSame(2, $draft->lines()->count()); // the two $0 placeholders never reach a draft
        $this->assertSame(2, Charge::query()->where('job_id', $job)->where('status', 'needs_review')->count());

        // The draft page: the warning box, the override checkbox next to 开出发票, no badge yet.
        $page = $this->actingAs($finance)->get("/billing/invoices/{$draft->id}")->assertOk();
        $page->assertSee(__('billing.invoices.review_warning_title', ['n' => 2]))->assertSee('name="unpriced_override"', false)->assertSee(__('billing.invoices.unpriced_override_label'))
            ->assertSee(__('billing.invoices.unpriced_override_hint'))->assertDontSee(__('billing.invoices.unpriced_override_badge'));

        // Without the tick: refused in Chinese, still a draft, no PDF, no number.
        $this->actingAs($finance)->from("/billing/invoices/{$draft->id}")->post("/billing/invoices/{$draft->id}/issue")->assertRedirect("/billing/invoices/{$draft->id}")
            ->assertSessionHasErrors(['invoice' => __('billing.errors.unpriced_block', ['n' => 2, 'label' => __('billing.invoices.unpriced_override_label')])]);
        $this->assertMatchesRegularExpression(self::CJK, session('errors')->first('invoice'));
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertStringStartsWith('DR-', $draft->fresh()->invoice_no);
        $this->assertDatabaseMissing('documents', ['type' => 'invoice']);
        $this->assertSame(0, Activity::query()->where('description', 'issued_with_unpriced_override')->count());

        // The service refuses the same way (no signed-in user needed).
        try {
            app(InvoiceService::class)->issue($draft->fresh());
            $this->fail('unpriced items must block issue()');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('unpriced', $e->getMessage());
        }

        // With the tick: issued; the override is on the invoice (notes), in the activity log with the ids left behind, and on the page as a badge + note.
        $this->actingAs($finance)->post("/billing/invoices/{$draft->id}/issue", ['unpriced_override' => 1])->assertRedirect("/billing/invoices/{$draft->id}")->assertSessionHasNoErrors();
        $invoice = $draft->fresh();
        $this->assertSame('issued', $invoice->status);
        $this->assertStringStartsWith('INV-', $invoice->invoice_no);
        $this->assertSame(13200, $invoice->total_cents); // 120.00 + GST — the unpriced freight is NOT on it
        $this->assertStringContainsString(__('billing.invoices.unpriced_override_label'), (string) $invoice->notes);
        $this->assertStringContainsString($finance->name, (string) $invoice->notes);
        $this->assertStringContainsString('TR-DELIVERY-BASE', (string) $invoice->notes);
        $log = Activity::query()->where('log_name', 'invoice')->where('description', 'issued_with_unpriced_override')->where('subject_id', $invoice->id)->sole();
        $this->assertSame($finance->id, (int) $log->causer_id);
        $this->assertCount(2, $log->properties['unpriced_charge_ids']);
        $this->actingAs($finance)->get("/billing/invoices/{$invoice->id}")->assertOk()->assertSee(__('billing.invoices.unpriced_override_badge'))->assertSee(__('billing.invoices.notes'))->assertSee('TR-DELIVERY-BASE');
        // The placeholders are untouched — still waiting for a price, still out of the pool.
        $this->assertSame(2, Charge::query()->where('job_id', $job)->where('status', 'needs_review')->count());
        // The stored PDF stays English (the Chinese note is not printed on the client's document).
        $html = view('billing::invoices.pdf', app(InvoiceService::class)->pdfData($invoice))->render();
        $this->assertDoesNotMatchRegularExpression(self::CJK, $html);
    }

    public function test_a_draft_without_unpriced_items_issues_as_before_with_no_override_offered(): void
    {
        Storage::fake('local');
        $client = $this->client(['invoice_mode' => 'per_job']);
        $finance = $this->staff('finance');
        $draft = app(InvoiceService::class)->draftForJob($this->jobWithCharges($client, $finance->id, false));

        $this->actingAs($finance)->get("/billing/invoices/{$draft->id}")->assertOk()->assertDontSee('name="unpriced_override"', false)->assertDontSee(__('billing.invoices.review_warning_hint'));
        $this->actingAs($finance)->post("/billing/invoices/{$draft->id}/issue")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('issued', $draft->fresh()->status);
        $this->assertNull($draft->fresh()->notes);
        $this->assertSame(0, Activity::query()->where('description', 'issued_with_unpriced_override')->count());
        $this->actingAs($finance)->get("/billing/invoices/{$draft->id}")->assertOk()->assertDontSee(__('billing.invoices.unpriced_override_badge'));
    }

    public function test_a_draft_line_can_be_removed_one_by_one_back_to_the_pool_and_never_from_an_issued_invoice(): void
    {
        Storage::fake('local');
        $client = $this->client(['invoice_mode' => 'per_job']);
        $finance = $this->staff('finance');
        $invoices = app(InvoiceService::class);
        $job = $this->jobWithCharges($client, $finance->id, false);
        $draft = $invoices->draftForJob($job);
        [$putaway, $labels] = $draft->lines()->orderBy('id')->get();
        $this->assertSame([12000, 1200, 13200], [$draft->subtotal_cents, $draft->gst_cents, $draft->total_cents]);

        // The draft page offers 移出草稿 per line; the pool no longer lists the Job (both charges are drafted).
        $this->actingAs($finance)->get("/billing/invoices/{$draft->id}")->assertOk()->assertSee(route('billing.invoices.lines.destroy', [$draft, $putaway]))->assertSee(__('billing.invoices.remove_line'));
        $this->actingAs($finance)->get('/billing/unbilled')->assertOk()->assertDontSee(route('billing.invoices.draft_job', $job));

        // Remove the putaway line: charge back in the pool (status untouched, no invoice line), line gone, totals recomputed, Job still on the draft.
        $this->actingAs($finance)->delete("/billing/invoices/{$draft->id}/lines/{$putaway->id}")->assertRedirect("/billing/invoices/{$draft->id}")->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('billing.invoices.line_removed', ['code' => 'WH-PUTAWAY-PLT', 'id' => $putaway->charge_id]));
        $charge = Charge::query()->findOrFail($putaway->charge_id);
        $this->assertSame(['pending', null], [$charge->status, $charge->invoice_line_id]);
        $this->assertDatabaseMissing('invoice_lines', ['id' => $putaway->id]);
        $draft->refresh();
        $this->assertSame([3000, 300, 3300], [$draft->subtotal_cents, $draft->gst_cents, $draft->total_cents]);
        $this->assertSame([$job], $draft->jobs()->pluck('jobs.id')->all());
        $this->actingAs($finance)->get('/billing/unbilled')->assertOk()->assertSee(route('billing.invoices.append_job', [$draft, $job]))->assertSee(__('billing.unbilled.append_to_draft')); // back in the pool, offered 并入该草稿
        $this->actingAs($finance)->get("/billing/invoices/{$draft->id}")->assertOk()->assertSee(__('billing.invoices.new_charges_for_job', ['job' => $charge->job->job_no, 'n' => 1, 'amount' => '$90.00']));

        // A line of another invoice → 404; a customer service user → 403.
        $other = $invoices->draftForJob($this->jobWithCharges($client, $finance->id, false));
        $foreign = $other->lines()->first();
        $this->actingAs($finance)->delete("/billing/invoices/{$draft->id}/lines/{$foreign->id}")->assertNotFound();
        $this->actingAs($this->staff('customer_service'))->delete("/billing/invoices/{$draft->id}/lines/{$labels->id}")->assertForbidden();

        // Remove the last line: the draft stays, empty, with a hint and a disabled 开出发票; issuing it is refused in Chinese.
        $this->actingAs($finance)->delete("/billing/invoices/{$draft->id}/lines/{$labels->id}")->assertRedirect()->assertSessionHasNoErrors();
        $draft->refresh();
        $this->assertSame([0, 0, 0, 0], [$draft->lines()->count(), $draft->subtotal_cents, $draft->gst_cents, $draft->total_cents]);
        $this->assertSame([], $draft->jobs()->pluck('jobs.id')->all());
        $this->assertSame(2, Charge::query()->where('job_id', $job)->whereNull('invoice_line_id')->where('status', 'pending')->count());
        $this->actingAs($finance)->get("/billing/invoices/{$draft->id}")->assertOk()->assertSee(__('billing.invoices.empty_draft_hint'))->assertSee('<button type="submit" disabled>', false);
        $this->actingAs($finance)->post("/billing/invoices/{$draft->id}/issue")->assertRedirect()->assertSessionHasErrors(['invoice' => __('billing.errors.empty_draft', ['no' => $draft->invoice_no])]);
        $this->assertSame('draft', $draft->fresh()->status);
        // The pool offers 按此 Job 开票 again (no open draft holds the Job any more).
        $this->actingAs($finance)->get('/billing/unbilled')->assertOk()->assertSee(route('billing.invoices.draft_job', $job));

        // An issued invoice never loses a line.
        $issued = $invoices->issue($other);
        $line = $issued->lines()->first();
        $this->actingAs($finance)->from("/billing/invoices/{$issued->id}")->delete("/billing/invoices/{$issued->id}/lines/{$line->id}")->assertRedirect("/billing/invoices/{$issued->id}")
            ->assertSessionHasErrors(['invoice' => __('billing.errors.only_drafts_remove_line', ['no' => $issued->invoice_no])]);
        $this->assertSame(2, $issued->lines()->count());
        $this->assertSame('invoiced', Charge::query()->findOrFail($line->charge_id)->status);
        $this->actingAs($finance)->get("/billing/invoices/{$issued->id}")->assertOk()->assertDontSee(__('billing.invoices.remove_line'));
        $this->assertSame(1, Invoice::query()->where('status', 'draft')->count());
    }
}
