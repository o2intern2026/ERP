<?php

namespace App\Modules\Platform\Services;

use App\Models\User;
use App\Modules\Platform\Models\Approval;
use App\Support\Enums;
use App\Support\Exceptions\RuleViolation;
use InvalidArgumentException;

/** A19 (PLT-7): sensitive actions wait for a second person. The requester can never approve their own request. */
final class ApprovalService
{
    /** @param array{client_id?:?int, job_id?:?int, request_note?:?string, payload?:?array<string, mixed>} $attributes */
    public function request(string $type, string $subjectType, int $subjectId, User $requestedBy, array $attributes = []): Approval
    {
        if (! in_array($type, Enums::APPROVAL_TYPES, true)) {
            throw new InvalidArgumentException("Unknown approval type: {$type}");
        }

        $open = Approval::query()->withoutGlobalScopes()->where('type', $type)->where('subject_type', $subjectType)->where('subject_id', $subjectId)->where('status', 'pending')->first();
        if ($open !== null) {
            return $open; // one open request per subject
        }

        return Approval::query()->create([
            'type' => $type, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'client_id' => $attributes['client_id'] ?? null, 'job_id' => $attributes['job_id'] ?? null,
            'requested_by' => $requestedBy->id, 'request_note' => $attributes['request_note'] ?? null, 'payload' => $attributes['payload'] ?? null,
            'status' => 'pending',
        ]);
    }

    public function approve(Approval $approval, User $by, ?string $note = null): Approval
    {
        return $this->decide($approval, $by, 'approved', $note);
    }

    public function reject(Approval $approval, User $by, ?string $note = null): Approval
    {
        return $this->decide($approval, $by, 'rejected', $note);
    }

    public function cancel(Approval $approval, User $by): Approval
    {
        if (! $approval->isPending()) {
            throw new RuleViolation('Only pending approvals can be cancelled.', 'platform.approvals.errors.not_pending');
        }
        if ($approval->requested_by !== $by->id && ! $by->hasRole('admin')) {
            throw new RuleViolation('Only the requester or an admin can cancel a request.', 'platform.approvals.errors.cancel_not_allowed');
        }
        $approval->update(['status' => 'cancelled', 'decided_by' => $by->id, 'decided_at' => now()]);

        return $approval->fresh();
    }

    public function isApproved(string $type, string $subjectType, int $subjectId): bool
    {
        return Approval::query()->withoutGlobalScopes()->where('type', $type)->where('subject_type', $subjectType)->where('subject_id', $subjectId)->where('status', 'approved')->exists();
    }

    private function decide(Approval $approval, User $by, string $status, ?string $note): Approval
    {
        if (! $approval->isPending()) {
            throw new RuleViolation("Approval #{$approval->id} is already {$approval->status}.", 'platform.approvals.errors.already_decided', ['id' => $approval->id, 'status' => __('platform.approvals.statuses.'.$approval->status)]);
        }
        if ($approval->requested_by === $by->id) {
            throw new RuleViolation('A request must be decided by a second person (PLT-7).', 'platform.approvals.errors.self_decide');
        }

        $approval->update(['status' => $status, 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note]);

        return $approval->fresh();
    }
}
