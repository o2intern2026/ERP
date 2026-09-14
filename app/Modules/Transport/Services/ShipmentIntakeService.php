<?php

namespace App\Modules\Transport\Services;

use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Support\Contracts\RateService;
use App\Support\Contracts\TransportOptionService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Applies the Orders/Warehouse event contracts to Transport-owned shipment state. */
final class ShipmentIntakeService
{
    /** Tailgate weight threshold when the client's card names none (Orders TailgateRule::DEFAULT_WEIGHT_KG). */
    private const DEFAULT_TAILGATE_KG = 25.0;

    /** Once the collection is booked the request belongs to the dispatcher: a re-request / cancel from the ASN is refused and logged (#124). */
    private const LOCKED_STATUSES = ['booked', 'dispatched', 'in_transit', 'delivered', 'failed'];

    public function __construct(
        private readonly TransportOptionService $quotes,
        private readonly ShipmentProgressService $progress,
        private readonly ?RateService $rates = null,
    ) {}

    /**
     * 我方上门提货 (CHANGE_REQUESTS #124): a 预报单's collection request opens ONE inbound collection shipment per ASN — sender = the
     * client's pickup address, receiver = our warehouse, no order — and quotes it at the FINAL stage straight away (the declared
     * packages are the final list, as for pickup_deliver). A re-request before booking (higher activity_version) re-quotes the
     * same shipment: status back to quoting, earlier quotes superseded, the selection cleared. An older or equal version is a
     * replay and does nothing; after booking the request is refused and logged — the dispatcher handles it on the shipment.
     *
     * @param  array<string, mixed>  $envelope
     */
    public function fromAsnCollection(array $envelope): ?Shipment
    {
        $payload = $envelope['payload'];
        $this->requireKeys($payload, ['asn_id', 'asn_no', 'client_id', 'job_id', 'collection_address', 'packages']);
        $version = (int) ($payload['activity_version'] ?? 1);

        $shipment = DB::transaction(function () use ($payload, $version): ?Shipment {
            $existing = Shipment::query()
                ->where('asn_id', (int) $payload['asn_id'])
                ->where('shipment_type', 'inbound_collection')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            $attributes = [
                'service_level' => (string) ($payload['service_level'] ?? 'standard'),
                'tailgate_required' => $this->collectionNeedsTailgate((int) $payload['client_id'], $payload['packages'] ?? [], $payload['lines'] ?? []),
                'asn_activity_version' => $version,
            ];

            if ($existing === null) {
                return Shipment::query()->create($attributes + [
                    'shipment_no' => $this->shipmentNumber((string) $payload['asn_no']),
                    'job_id' => (int) $payload['job_id'],
                    'client_id' => (int) $payload['client_id'],
                    'order_id' => null,
                    'asn_id' => (int) $payload['asn_id'],
                    'fulfilment_id' => null,
                    'shipment_type' => 'inbound_collection',
                    'status' => 'quoting',
                ]);
            }

            if ($existing->job_id !== (int) $payload['job_id'] || $existing->client_id !== (int) $payload['client_id']) {
                throw new DomainException(__('transport.integration.identity_mismatch'));
            }
            if ($version <= (int) $existing->asn_activity_version && $existing->status !== 'booking_cancelled') {
                return null; // replay of a request already applied
            }
            if (in_array($existing->status, self::LOCKED_STATUSES, true)) {
                Log::warning('transport: collection re-request refused, the shipment is already booked', ['shipment' => $existing->shipment_no, 'asn_id' => $existing->asn_id, 'version' => $version]);

                return null;
            }

            // Re-request (or a fresh request after 改为客户自送): the same shipment goes back to quoting and every earlier quote is history.
            TransportQuote::query()->where('shipment_id', $existing->id)->whereIn('status', ['quoted', 'selected'])->update(['status' => 'requoted']);
            $existing->fill($attributes + ['status' => 'quoting', 'selected_quote_id' => null, 'carrier_id' => null, 'booking_ref' => null, 'tracking_number' => null])->save();

            return $existing->refresh();
        });

        if ($shipment !== null && in_array($shipment->status, ['quoting', 'quoted'], true)) {
            $this->quotes->quote($shipment->id, 'final', $this->moment($payload['requested_at'] ?? $envelope['occurred_at'] ?? null));
        }

        return $shipment?->refresh();
    }

    /**
     * 改为客户自送 (asn.collection_cancelled): the unbooked collection shipment is cancelled with its quotes. A booked one stays —
     * the dispatcher cancels the booking on the shipment page (logged, never thrown: the ASN side has already moved on).
     *
     * @param  array<string, mixed>  $envelope
     */
    public function cancelAsnCollection(array $envelope): ?Shipment
    {
        $payload = $envelope['payload'];
        $this->requireKeys($payload, ['asn_id']);

        return DB::transaction(function () use ($payload): ?Shipment {
            $shipment = Shipment::query()
                ->where('asn_id', (int) $payload['asn_id'])
                ->where('shipment_type', 'inbound_collection')
                ->latest('id')
                ->lockForUpdate()
                ->first();
            if ($shipment === null || $shipment->status === 'booking_cancelled') {
                return $shipment;
            }
            if (in_array($shipment->status, self::LOCKED_STATUSES, true)) {
                Log::warning('transport: collection cancel ignored, the shipment is already booked', ['shipment' => $shipment->shipment_no, 'asn_id' => $shipment->asn_id]);

                return $shipment;
            }

            TransportQuote::query()->where('shipment_id', $shipment->id)->whereIn('status', ['quoted', 'selected'])->update(['status' => 'booking_cancelled']);
            $shipment->fill(['status' => 'booking_cancelled', 'selected_quote_id' => null, 'carrier_id' => null])->save();

            return $shipment->refresh();
        });
    }

    /**
     * Pickup-side tailgate: any declared piece (or, without packages, any goods line's per-carton weight) at or above the client's
     * TR-TAILGATE threshold (`tailgate_weight_kg`, default 25 kg). The receiver is our warehouse, so "residential" never applies.
     *
     * @param  list<array<string, mixed>>  $packages
     * @param  list<array<string, mixed>>  $lines
     */
    private function collectionNeedsTailgate(int $clientId, array $packages, array $lines): bool
    {
        $rates = $this->rates ?? app(RateService::class);
        $threshold = (float) (($rates->thresholds($clientId, 'TR-TAILGATE')['tailgate_weight_kg'] ?? null) ?: self::DEFAULT_TAILGATE_KG);
        $pieces = $packages !== []
            ? array_map(fn ($p) => (float) ($p['weight_kg'] ?? 0), $packages)
            : array_map(fn ($l) => (float) ($l['weight_kg'] ?? 0) / max(1, (int) ($l['expected_cartons'] ?? 1)), $lines);
        $heaviest = $pieces === [] ? 0.0 : max($pieces);

        return $heaviest > 0 && $heaviest >= $threshold;
    }

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
            // CHANGE_REQUESTS #120: the order's confirmation moment, so an automatic final confirmation is dated (and its urgency judged)
            // when the order was confirmed, not when the outbox row happened to be processed.
            $this->quotes->quote($shipment->id, $stage, $this->moment($payload['confirmed_at'] ?? $envelope['occurred_at'] ?? null));
        }

        return $shipment->refresh();
    }

    private function moment(mixed $raw): ?CarbonImmutable
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw);
        } catch (\Throwable) {
            return null;
        }
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
