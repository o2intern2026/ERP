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
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** A19 Approval Centre: pending requests for admin / finance; the requester sees their own. */
class ApprovalController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(Enums::APPROVAL_STATUSES)], 'type' => ['nullable', Rule::in(Enums::APPROVAL_TYPES)]]);

        return view('platform::approvals.index', [
            'approvals' => Approval::query()->with(['requester', 'decider', 'client'])
                ->where('status', $filters['status'] ?? 'pending')
                ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
                ->orderByDesc('id')->paginate(50)->withQueryString(),
            'filters' => $filters, 'statuses' => Enums::APPROVAL_STATUSES, 'types' => Enums::APPROVAL_TYPES,
            'counts' => Approval::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
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
