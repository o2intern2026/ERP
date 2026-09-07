<?php

namespace App\Modules\Transport\Services;

use App\Modules\Platform\Models\ExceptionRecord;
use App\Modules\Transport\Events\DeliveryFailed;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TrackingEvent;
use App\Support\Contracts\CarrierAdapter;
use App\Support\Contracts\ExceptionService;
use App\Support\Outbox\OutboxPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class TrackingSyncService
{
    /** @var array<string, CarrierAdapter> */
    private array $adapters = [];

    /** @param iterable<CarrierAdapter> $adapters */
    public function __construct(
        iterable $adapters,
        private readonly ShipmentProgressService $progress,
        private readonly ExceptionService $exceptions,
        private readonly OutboxPublisher $outbox,
    ) {
        foreach ($adapters as $adapter) {
            if (($adapter->capabilities()['tracking'] ?? 'none') === 'poll') {
                $this->adapters[$adapter->source()] = $adapter;
            }
        }
    }

    public function syncActive(): int
    {
        if ($this->adapters === []) {
            return 0;
        }

        $created = 0;
        Shipment::query()
            ->with('selectedQuote')
            ->where('shipment_type', 'outbound')
            ->whereIn('status', ['booked', 'dispatched', 'in_transit'])
            ->whereNotNull('booking_ref')
            ->whereHas('selectedQuote', fn ($query) => $query->whereIn('source', array_keys($this->adapters)))
            ->orderBy('id')
            ->chunkById(100, function ($shipments) use (&$created): void {
                foreach ($shipments as $shipment) {
                    $created += $this->syncShipment($shipment);
                }
            });

        return $created;
    }

    public function syncShipment(Shipment $shipment): int
    {
        $shipment->loadMissing('selectedQuote');
        $source = $shipment->selectedQuote?->source;
        $adapter = $source === null ? null : ($this->adapters[$source] ?? null);
        if ($adapter === null || trim((string) $shipment->booking_ref) === '') {
            return 0;
        }

        try {
            $events = $adapter->tracking($shipment->booking_ref);
        } catch (Throwable $exception) {
            $this->raiseOnce($shipment, 'manual_transport', __('transport.tracking.poll_failed', [
                'message' => $exception->getMessage(),
            ]));

            return 0;
        }

        if ($events === []) {
            $this->raiseOnce($shipment, 'manual_transport', __('transport.tracking.no_events'));

            return 0;
        }

        usort($events, fn (array $left, array $right): int => strcmp(
            (string) ($left['occurred_at'] ?? ''),
            (string) ($right['occurred_at'] ?? ''),
        ));

        $created = 0;
        foreach ($events as $event) {
            $created += $this->persist($shipment, $event) ? 1 : 0;
        }

        return $created;
    }

    /** @param array{status:string, description:?string, location:?string, occurred_at:?string, raw:array<string, mixed>} $event */
    private function persist(Shipment $shipment, array $event): bool
    {
        $occurredAt = $this->occurredAt($event['occurred_at'] ?? null);

        return DB::transaction(function () use ($shipment, $event, $occurredAt): bool {
            $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);
            $exists = TrackingEvent::query()
                ->where('shipment_id', $locked->id)
                ->where('status', $event['status'])
                ->where('description', $event['description'] ?? null)
                ->where('location', $event['location'] ?? null)
                ->where('occurred_at', $occurredAt)
                ->exists();
            if ($exists) {
                return false;
            }

            TrackingEvent::query()->create([
                'shipment_id' => $locked->id,
                'status' => $event['status'],
                'description' => $event['description'] ?? null,
                'location' => $event['location'] ?? null,
                'source' => 'api',
                'occurred_at' => $occurredAt,
                'raw' => $event['raw'] ?? [],
                'created_at' => now(),
            ]);

            $target = $this->targetStatus($event['status']);
            $at = $occurredAt ?? now();
            if ($target !== null) {
                $this->progress->advance($locked, $target, $at);
            }

            if ($target === 'failed') {
                $attemptNo = TrackingEvent::query()
                    ->where('shipment_id', $locked->id)
                    ->get(['status'])
                    ->filter(fn (TrackingEvent $tracking): bool => $this->targetStatus($tracking->status) === 'failed')
                    ->count();
                $this->outbox->publish(new DeliveryFailed([
                    'shipment_id' => $locked->id,
                    'shipment_no' => $locked->shipment_no,
                    'job_id' => $locked->job_id,
                    'client_id' => $locked->client_id,
                    'order_id' => $locked->order_id,
                    'failed_at' => $at->toIso8601String(),
                    'failure_reason' => $event['description'] ?: $event['status'],
                    'attempt_no' => $attemptNo,
                    'reported_by_type' => 'carrier_api',
                ], jobId: $locked->job_id, clientId: $locked->client_id, correlationId: $locked->shipment_no));
                $this->raiseOnce($locked, 'delivery_failed', __('transport.tracking.delivery_failed', [
                    'shipment' => $locked->shipment_no,
                    'reason' => $event['description'] ?: $event['status'],
                ]));
            } elseif ($this->isDelayed($event['status'])) {
                $this->raiseOnce($locked, 'delivery_failed', __('transport.tracking.delayed', [
                    'shipment' => $locked->shipment_no,
                ]));
            } elseif ($target === 'delivered'
                && ! $locked->pods()->whereNotNull('delivered_at')->exists()) {
                $this->raiseOnce($locked, 'manual_transport', __('transport.tracking.pod_required', [
                    'shipment' => $locked->shipment_no,
                ]));
            }

            return true;
        });
    }

    private function targetStatus(string $carrierStatus): ?string
    {
        $status = strtolower(trim($carrierStatus));

        return match (true) {
            str_contains($status, 'fail'), str_contains($status, 'undeliverable'), str_contains($status, 'exception') => 'failed',
            str_contains($status, 'deliver'), str_contains($status, 'complete') => 'delivered',
            str_contains($status, 'in transit'), str_contains($status, 'out for delivery'), str_contains($status, 'on board') => 'in_transit',
            str_contains($status, 'dispatch'), str_contains($status, 'picked up'), str_contains($status, 'collected') => 'dispatched',
            default => null,
        };
    }

    private function isDelayed(string $carrierStatus): bool
    {
        $status = strtolower($carrierStatus);

        return str_contains($status, 'delay') || str_contains($status, 'late');
    }

    private function occurredAt(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function raiseOnce(Shipment $shipment, string $type, string $message): void
    {
        $exists = ExceptionRecord::query()->withoutGlobalScopes()
            ->where('type', $type)
            ->where('source_module', 'transport')
            ->where('source_type', 'shipment')
            ->where('source_id', $shipment->id)
            ->where('status', '!=', 'resolved')
            ->exists();

        if (! $exists) {
            $this->exceptions->raise($type, 'transport', [
                'job_id' => $shipment->job_id,
                'client_id' => $shipment->client_id,
                'order_id' => $shipment->order_id,
                'source_type' => 'shipment',
                'source_id' => $shipment->id,
                'message' => $message,
            ]);
        }
    }
}
