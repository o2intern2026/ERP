<?php

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Models\ExceptionRecord;
use App\Support\Contracts\ExceptionService as ExceptionServiceContract;
use App\Support\Enums;
use InvalidArgumentException;

/** The only writer of the shared `exceptions` table (A28 data side; the Exception Centre pages land in M6). */
final class ExceptionService implements ExceptionServiceContract
{
    public function raise(string $type, string $sourceModule, array $attributes = []): int
    {
        if (! in_array($type, Enums::EXCEPTION_TYPES, true)) {
            throw new InvalidArgumentException("Unknown exception type: {$type}");
        }
        if (! in_array($sourceModule, Enums::SOURCE_MODULES, true)) {
            throw new InvalidArgumentException("Unknown source module: {$sourceModule}");
        }
        if ($type === 'hold' && ! in_array($attributes['hold_type'] ?? null, Enums::HOLD_TYPES, true)) {
            throw new InvalidArgumentException('A hold needs a hold_type from contracts/enums.md');
        }

        $record = ExceptionRecord::query()->create([
            'type' => $type,
            'source_module' => $sourceModule,
            'status' => 'open',
            'job_id' => $attributes['job_id'] ?? null,
            'client_id' => $attributes['client_id'] ?? null,
            'order_id' => $attributes['order_id'] ?? null,
            'source_type' => $attributes['source_type'] ?? null,
            'source_id' => $attributes['source_id'] ?? null,
            'hold_type' => $attributes['hold_type'] ?? null,
            'message' => $attributes['message'] ?? null,
            'owner_id' => $attributes['owner_id'] ?? null,
            'created_by' => $attributes['created_by'] ?? auth()->id(),
        ]);

        return $record->id;
    }

    public function resolve(int $exceptionId, int $resolvedBy, ?string $note = null): void
    {
        $record = ExceptionRecord::query()->withoutGlobalScopes()->findOrFail($exceptionId);

        $update = ['status' => 'resolved', 'resolved_by' => $resolvedBy, 'resolved_at' => now()];

        if ($record->isHold()) {
            $update += ['released_by' => $resolvedBy, 'released_at' => now(), 'release_reason' => $note];
        }

        $record->update($update);
    }

    public function resolveOpen(string $type, int $orderId, ?int $resolvedBy = null, ?string $note = null): int
    {
        $records = ExceptionRecord::query()->withoutGlobalScopes()->where('type', $type)->where('order_id', $orderId)->whereIn('status', ['open', 'in_progress'])->get();
        foreach ($records as $record) {
            $record->update(['status' => 'resolved', 'resolved_by' => $resolvedBy ?? auth()->id(), 'resolved_at' => now(), 'message' => $note ? trim($record->message.' — '.$note) : $record->message]);
        }

        return $records->count();
    }

    public function assign(int $exceptionId, ?int $ownerId): void
    {
        ExceptionRecord::query()->withoutGlobalScopes()->whereKey($exceptionId)->where('status', '!=', 'resolved')->update(['owner_id' => $ownerId]);
    }

    public function start(int $exceptionId, int $userId): void
    {
        ExceptionRecord::query()->withoutGlobalScopes()->whereKey($exceptionId)->where('status', 'open')->update(['status' => 'in_progress', 'owner_id' => $userId]);
    }

    public function hasActiveHold(string $holdType, ?int $clientId = null, ?int $orderId = null): bool
    {
        return ExceptionRecord::query()->withoutGlobalScopes()
            ->where('type', 'hold')
            ->where('hold_type', $holdType)
            ->where('status', '!=', 'resolved')
            ->when($clientId !== null, fn ($q) => $q->where('client_id', $clientId))
            ->when($orderId !== null, fn ($q) => $q->where(fn ($q) => $q->where('order_id', $orderId)->orWhereNull('order_id')))
            ->exists();
    }
}
