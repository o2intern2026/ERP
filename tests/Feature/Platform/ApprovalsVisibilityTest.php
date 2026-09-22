<?php

namespace Tests\Feature\Platform;

use App\Modules\Billing\Models\RateCard;
use App\Modules\Platform\Models\Approval;
use App\Modules\Platform\Services\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * CR #142 decision 5 (lead 2026-09-22): the approval centre (`/admin/approvals`, list and every action) is restricted to admin | finance —
 * the roles that approve rate-card changes and credit notes, and the only roles that request them. Other staff get 403 and no nav entry.
 * A requester still follows the status of their own request on the item's page (rate card: 审批中 badge + locked hint; credit note: 审批中 hint).
 */
class ApprovalsVisibilityTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_only_admin_and_finance_reach_the_approval_centre_and_see_its_nav_entry(): void
    {
        $client = $this->client();
        $requester = $this->staff('finance');
        $approval = app(ApprovalService::class)->request('credit_note', 'credit_note', 12, $requester, ['client_id' => $client->id, 'request_note' => 'damaged goods']);

        foreach (['customer_service', 'dispatcher', 'warehouse_supervisor', 'warehouse_operator', 'transport_operator'] as $role) {
            $user = $this->staff($role);
            $this->actingAs($user)->get('/admin/approvals')->assertForbidden();
            $this->actingAs($user)->post("/admin/approvals/{$approval->id}/cancel")->assertForbidden();
            $this->actingAs($user)->post("/admin/approvals/{$approval->id}/approve", ['note' => 'ok'])->assertForbidden();
            $this->actingAs($user)->post("/admin/approvals/{$approval->id}/reject")->assertForbidden();
        }
        $this->assertSame('pending', $approval->fresh()->status);
        // The nav: no 审批 entry for a customer-service user (the exceptions and documents entries stay), one for finance and admin.
        $this->actingAs($this->staff('customer_service'))->get('/jobs')->assertOk()->assertDontSee(route('platform.approvals.index'))->assertSee(route('platform.exceptions.index'))->assertSee(route('platform.documents.index'));

        $this->actingAs($requester)->get('/admin/approvals')->assertOk()->assertSee('damaged goods')->assertSee(__('platform.approvals.awaiting_second_person'));
        $this->actingAs($requester)->get('/jobs')->assertOk()->assertSee(route('platform.approvals.index'));
        $this->actingAs($this->staff('admin'))->get('/admin/approvals')->assertOk()->assertSee('damaged goods');
        $this->actingAs($this->staff('admin'))->get('/jobs')->assertOk()->assertSee(route('platform.approvals.index'));
        $this->actingAs($this->staff('finance'))->post("/admin/approvals/{$approval->id}/approve", ['note' => 'ok'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('approved', $approval->fresh()->status);
    }

    public function test_a_requester_follows_their_rate_card_request_on_the_card_page(): void
    {
        $this->client(); // seeds the standard card
        $finance = $this->staff('finance');
        $standard = RateCard::query()->where('is_standard', true)->where('status', 'active')->sole();
        $this->actingAs($finance)->post("/billing/rate-cards/{$standard->id}/new-version", ['effective_from' => today()->toDateString(), 'notes' => 'v2'])->assertRedirect();
        $v2 = RateCard::query()->where('is_standard', true)->where('version', 2)->sole();

        $this->actingAs($finance)->post("/billing/rate-cards/{$v2->id}/request-activation")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, Approval::query()->where('type', 'rate_card_change')->where('subject_id', $v2->id)->where('status', 'pending')->count());
        // The card page carries the request's status (审批中 badge, locked hint with the link to the centre) — the requester needs no other page.
        $this->actingAs($finance)->get(route('billing.rate_cards.show', $v2))->assertOk()
            ->assertSee(__('billing.rate_cards.pending_badge'))->assertSee(__('billing.rate_cards.locked_badge'))->assertSee(__('billing.rate_cards.locked_hint'))->assertSee(route('platform.approvals.index', ['type' => 'rate_card_change']));

        $second = $this->staff('finance');
        $this->actingAs($second)->post('/admin/approvals/'.Approval::query()->where('subject_id', $v2->id)->sole()->id.'/approve', ['note' => 'ok'])->assertRedirect();
        $this->actingAs($finance)->get(route('billing.rate_cards.show', $v2))->assertOk()->assertSee(__('billing.rate_cards.approved_badge'));
    }
}
