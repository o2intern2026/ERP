<?php

namespace App\Modules\Transport\Services;

use App\Modules\Transport\Models\Shipment;
use App\Support\Contracts\TransportOptionService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Applies the Orders/Warehouse event contracts to Transport-owned shipment state. */
final class ShipmentIntakeService
{
    public function __construct(
        private readonly TransportOptionService $quotes,
        private readonly ShipmentProgressService $progress,
    ) {}

    /** @param array<string, mixed> $envelope */
    public function fromConfirmedOrder(array $envelope): Shipment
    {
        $payload = $envelope['payload'];
        $this->requireKeys($payload, ['order_id', 'order_no', 'order_type', 'client_id', 'job_id']);

        $shipment = DB::transaction(function () use ($payload): Shipment {
            $existing = Shipment::query()
                ->where('order_id', (int) $payload['order_id'])
                ->whereNull('fulfilment_id')
                ->where('shipment_type', 'outbound')
                ->oldest('id')
                ->first();

            if ($existing !== null) {
                $this->assertIdentity($existing, $payload);

                return $existing;
            }

            return Shipment::query()->create([
                'shipment_no' => $this->shipmentNumber((string) $payload['order_no']),
                'job_id' => (int) $payload['job_id'],
                'client_id' => (int) $payload['client_id'],
                'order_id' => (int) $payload['order_id'],
                'fulfilment_id' => null,
                'shipment_type' => 'outbound',
                'status' => 'quoting',
                'service_level' => (string) ($payload['service_level'] ?? 'standard'),
                'tailgate_required' => (bool) ($payload['tailgate_required'] ?? false),
            ]);
        });

        if (in_array($shipment->status, ['quoting', 'quoted'], true)) {
            $stage = $payload['order_type'] === 'pickup_deliver' ? 'final' : 'preliminary';
            $this->quotes->quote($shipment->id, $stage);
        }

        return $shipment->refresh();
    }

    /** @param array<string, mixed> $envelope */
    public function fromPackedOutbound(array $envelope): Shipment
    {
        $payload = $envelope['payload'];
        $this->requireKeys($payload, ['order_id', 'order_no', 'fulfilment_id', 'client_id', 'job_id', 'packages']);

        $shipment = DB::transaction(function () use ($payload): Shipment {
            $shipment = Shipment::query()
                ->where('order_id', (int) $payload['order_id'])
                ->where('fulfilment_id', (int) $payload['fulfilment_id'])
                ->where('shipment_type', 'outbound')
                ->oldest('id')
                ->first();

            if ($shipment === null) {
                $shipment = Shipment::query()
                    ->where('order_id', (int) $payload['order_id'])
                    ->whereNull('fulfilment_id')
                    ->where('shipment_type', 'outbound')
                    ->oldest('id')
                    ->first();
            }

            if ($shipment === null) {
                $template = Shipment::query()
                    ->where('order_id', (int) $payload['order_id'])
                    ->where('shipment_type', 'outbound')
                    ->oldest('id')
                    ->first();
                if ($template === null) {
                    throw new DomainException(__('transport.integration.confirmed_order_required'));
                }
                $this->assertIdentity($template, $payload);
                $shipment = Shipment::query()->create([
                    'shipment_no' => $this->fulfilmentShipmentNumber(
                        (string) $payload['order_no'],
                        (int) $payload['fulfilment_id'],
                    ),
                    'job_id' => (int) $payload['job_id'],
                    'client_id' => (int) $payload['client_id'],
                    'order_id' => (int) $payload['order_id'],
                    'fulfilment_id' => (int) $payload['fulfilment_id'],
                    'shipment_type' => 'outbound',
                    'status' => 'quoting',
                    'service_level' => $template->service_level,
                    'tailgate_required' => $template->tailgate_required,
                ]);
            }

            $this->assertIdentity($shipment, $payload);
            if ($shipment->fulfilment_id === null) {
                $shipment->update(['fulfilment_id' => (int) $payload['fulfilment_id']]);
            }

            return $shipment->refresh();
        });

        if (in_array($shipment->status, ['quoting', 'quoted', 'quote_confirmed'], true)) {
            $this->quotes->quote($shipment->id, 'final');
        }

        return $shipment->refresh();
    }

    /** @param array<string, mixed> $envelope */
    public function fromDispatchedOutbound(array $envelope): ?Shipment
    {
        $payload = $envelope['payload'];
        $this->requireKeys($payload, ['order_id', 'fulfilment_id', 'client_id', 'job_id', 'shipment_id', 'dispatched_at']);

        if ($payload['shipment_id'] === null) {
            return null;
        }

        $shipment = Shipment::query()->findOrFail((int) $payload['shipment_id']);
        $this->assertIdentity($shipment, $payload);
        if ($shipment->fulfilment_id !== (int) $payload['fulfilment_id']) {
            throw new DomainException(__('transport.integration.fulfilment_mismatch'));
        }

        if (in_array($shipment->status, ['dispatched', 'in_transit', 'delivered', 'failed'], true)) {
            return $shipment;
        }
        if ($shipment->status !== 'booked') {
            throw new DomainException(__('transport.integration.booking_required'));
        }

        return $this->progress->advance(
            $shipment,
            'dispatched',
            CarbonImmutable::parse((string) $payload['dispatched_at'], 'Australia/Melbourne'),
        );
    }

    /** @param array<string, mixed> $payload */
    private function assertIdentity(Shipment $shipment, array $payload): void
    {
        if ($shipment->order_id !== (int) $payload['order_id']
            || $shipment->job_id !== (int) $payload['job_id']
            || $shipment->client_id !== (int) $payload['client_id']) {
            throw new DomainException(__('transport.integration.identity_mismatch'));
        }
    }

    /** @param array<string, mixed> $payload @param list<string> $keys */
    private function requireKeys(array $payload, array $keys): void
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $payload)) {
                throw new DomainException(__('transport.integration.missing_field', ['field' => $key]));
            }
        }
    }

    private function shipmentNumber(string $orderNumber): string
    {
        $base = preg_replace('/[^A-Za-z0-9-]+/', '-', $orderNumber) ?: 'ORDER';

        return 'SHP-'.Str::upper(Str::limit($base, 26, ''));
    }

    private function fulfilmentShipmentNumber(string $orderNumber, int $fulfilmentId): string
    {
        $suffix = '-F'.$fulfilmentId;
        $base = preg_replace('/[^A-Za-z0-9-]+/', '-', $orderNumber) ?: 'ORDER';

        return 'SHP-'.Str::upper(Str::limit($base, 26 - strlen($suffix), '')).$suffix;
    }
}
