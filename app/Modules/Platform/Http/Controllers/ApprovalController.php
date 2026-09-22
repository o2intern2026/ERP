<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Models\Approval;
use App\Modules\Platform\Services\ApprovalService;
use App\Support\Enums;
use App\Support\Exceptions\RuleViolation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** A19 Approval Centre: pending requests for admin / finance; the requester sees their own. */
class ApprovalController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(Enums::APPROVAL_STATUSES)], 'type' => ['nullable', Rule::in(Enums::APPROVAL_TYPES)]]);

        $approvals = Approval::query()->with(['requester', 'decider', 'client'])
            ->where('status', $filters['status'] ?? 'pending')
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->orderByDesc('id')->paginate(50)->withQueryString();

        return view('platform::approvals.index', [
            'approvals' => $approvals,
            'subjectLinks' => $this->subjectLinks($approvals->getCollection()),
            'filters' => $filters, 'statuses' => Enums::APPROVAL_STATUSES, 'types' => Enums::APPROVAL_TYPES,
            'counts' => Approval::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    /**
     * Audit 2026-09-22 FIN-08 (CR #140): the approver saw "rate_card #5" as plain text — the subject is now a link to what is being approved
     * (a rate card → its page with the diff against the live version; a credit note → the invoice it reduces). Read-only lookups on Billing's
     * tables; other subject types keep the plain "type #id".
     *
     * @param  Collection<int, Approval>  $approvals
     * @return array<int, array{url: string, label: string}> approval id → link
     */
    private function subjectLinks(Collection $approvals): array
    {
        $cards = DB::table('rate_cards')->whereIn('id', $approvals->where('subject_type', 'rate_card')->pluck('subject_id'))->get(['id', 'name', 'version'])->keyBy('id');
        $notes = DB::table('credit_notes')->join('invoices', 'invoices.id', '=', 'credit_notes.invoice_id')
            ->whereIn('credit_notes.id', $approvals->where('subject_type', 'credit_note')->pluck('subject_id'))
            ->get(['credit_notes.id', 'credit_notes.credit_note_no', 'credit_notes.invoice_id', 'invoices.invoice_no'])->keyBy('id');

        $links = [];
        foreach ($approvals as $a) {
            if ($a->subject_type === 'rate_card' && isset($cards[$a->subject_id])) {
                $links[$a->id] = ['url' => route('billing.rate_cards.show', $a->subject_id), 'label' => __('platform.approvals.subjects.rate_card', ['name' => $cards[$a->subject_id]->name, 'version' => $cards[$a->subject_id]->version])];
            } elseif ($a->subject_type === 'credit_note' && isset($notes[$a->subject_id])) {
                $links[$a->id] = ['url' => route('billing.invoices.show', $notes[$a->subject_id]->invoice_id), 'label' => __('platform.approvals.subjects.credit_note', ['no' => $notes[$a->subject_id]->credit_note_no, 'invoice' => $notes[$a->subject_id]->invoice_no])];
            }
        }

        return $links;
    }

    public function approve(Request $request, Approval $approval, ApprovalService $service): RedirectResponse
    {
        return $this->decide($request, $approval, fn ($note) => $service->approve($approval, $request->user(), $note), 'approved');
    }

    public function reject(Request $request, Approval $approval, ApprovalService $service): RedirectResponse
    {
        return $this->decide($request, $approval, fn ($note) => $service->reject($approval, $request->user(), $note), 'rejected');
    }

    public function cancel(Request $request, Approval $approval, ApprovalService $service): RedirectResponse
    {
        try {
            $service->cancel($approval, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['approval' => RuleViolation::display($e)]);
        }

        return back()->with('status', __('platform.approvals.cancelled'));
    }

    private function decide(Request $request, Approval $approval, callable $action, string $key): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        try {
            $action($data['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['approval' => RuleViolation::display($e)]);
        }

        return back()->with('status', __('platform.approvals.'.$key));
    }
}
