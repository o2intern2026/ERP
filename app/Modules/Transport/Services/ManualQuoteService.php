<?php

namespace App\Modules\Transport\Services;

use App\Modules\Transport\Adapters\ManualCarrierAdapter;
use App\Modules\Transport\Models\CarrierService;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Support\Contracts\RateService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Human-entered quote path used while no live carrier account is configured. */
final class ManualQuoteService
{
    /** Largest value transport_quotes.markup_percent (decimal 5,2) can hold. */
    public const MAX_MARKUP_PERCENT = 999.99;

    public function __construct(
        private readonly ManualCarrierAdapter $adapter,
        private readonly ShipmentQuoteRequestFactory $requests,
        private readonly RateService $rates,
    ) {}

    public function record(
        Shipment $shipment,
        CarrierService $carrierService,
        string $stage,
        int $costCents,
        int $customerPriceCents,
        int $etaDays,
    ): TransportQuote {
        if (! in_array($shipment->status, ['quoting', 'quoted'], true)) {
            throw new DomainException(__('transport.manual_quote.invalid_status'));
        }
        if ($carrierService->source !== 'manual' || ! $carrierService->active) {
            throw new DomainException(__('transport.manual_quote.invalid_service'));
        }
        if (! in_array($stage, ['preliminary', 'final'], true)
            || $costCents < 1
            || $customerPriceCents < 1
            || $etaDays < 0) {
            throw new DomainException(__('transport.manual_quote.invalid_values'));
        }
        // 2026-09-10 audit: transport_quotes.markup_percent is decimal(5,2); anything above 999.99 % used to escape as a 500.
        if ((($customerPriceCents / $costCents) - 1) * 100 > self::MAX_MARKUP_PERCENT) {
            throw new DomainException(__('transport.manual_quote.markup_too_high'));
        }

        $request = $this->requests->build($shipment, $stage);
        if ($request === null) {
            throw new DomainException(__('transport.booking.details_unavailable'));
        }

        $option = $this->adapter->quote($request + [
            'manual_quotes' => [[
                'service_code' => 'manual.'.$carrierService->service_level,
                'service_level' => $carrierService->service_level,
                'cost_cents' => $costCents,
                'customer_price_cents' => $customerPriceCents,
                'eta_days' => $etaDays,
                'raw' => ['carrier_service_id' => $carrierService->id],
            ]],
        ])[0] ?? null;
        if ($option === null) {
            throw new DomainException(__('transport.manual_quote.invalid_values'));
        }

        return DB::transaction(function () use ($shipment, $carrierService, $stage, $option, $request): TransportQuote {
            $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);
            if (! in_array($locked->status, ['quoting', 'quoted'], true)) {
                throw new DomainException(__('transport.manual_quote.invalid_status'));
            }

            $quotedAt = now();
            $quote = TransportQuote::query()->create([
                'shipment_id' => $locked->id,
                'carrier_id' => $carrierService->carrier_id,
                'source' => 'manual',
                'service_level' => $carrierService->service_level,
                'cost_cents' => (int) $option['cost_cents'],
                'customer_price_cents' => (int) data_get($option, 'raw.customer_price_cents'),
                'markup_percent' => round((((int) data_get($option, 'raw.customer_price_cents') / (int) $option['cost_cents']) - 1) * 100, 2),
                'eta_days' => (int) $option['eta_days'],
                'is_recommended' => false,
                'is_cheapest' => false,
                'is_fastest' => false,
                'quote_stage' => $stage,
                'status' => 'quoted',
                'quoted_at' => $quotedAt,
                'expires_at' => $quotedAt->copy()->addDay(),
                'raw_response' => $option['raw'] + [
                    '_booking' => [
                        'service_code' => (string) $option['service_code'],
                        'quote_ref' => '',
                        'pickup_dates' => [],
                    ],
                    '_quote_request' => [
                        'zone' => $request['zone'] ?? '',
                        'items' => $request['items'] ?? [],
                        'receiver' => $request['receiver'] ?? [],
                    ],
                ],
            ]);

            if ($locked->status === 'quoting') {
                $locked->update(['status' => 'quoted']);
            }

            $this->rank($locked, $stage, $request['requested_date'] ?? null);

            return $quote->refresh();
        });
    }

    private function rank(Shipment $shipment, string $stage, mixed $requestedDate): void
    {
        /** @var Collection<int, TransportQuote> $quotes */
        $quotes = TransportQuote::query()
            ->where('shipment_id', $shipment->id)
            ->where('quote_stage', $stage)
            ->where('status', 'quoted')
            ->orderBy('id')
            ->get();
        if ($quotes->isEmpty()) {
            return;
        }

        $cheapest = (int) $quotes->min('customer_price_cents');
        $fastest = (int) $quotes->min('eta_days');
        $eligible = $quotes->filter(fn (TransportQuote $quote): bool => $this->meetsDate($quote, $requestedDate));
        $recommendedId = null;

        if ($eligible->isNotEmpty()) {
            $lowest = (int) $eligible->min('customer_price_cents');
            $thresholds = $this->rates->thresholds($shipment->client_id, 'TR-DELIVERY-BASE') ?? [];
            $preference = max(0.0, (float) ($thresholds['own_fleet_preference_percent'] ?? 0));
            $ownFleet = $eligible
                ->filter(fn (TransportQuote $quote): bool => $quote->source === 'own_fleet'
                    && $quote->customer_price_cents <= (int) floor($lowest * (1 + $preference / 100)))
                ->sortBy([['customer_price_cents', 'asc'], ['eta_days', 'asc']])
                ->first();
            $recommendedId = $ownFleet?->id
                ?? $eligible->sortBy([['customer_price_cents', 'asc'], ['eta_days', 'asc']])->first()?->id;
        }

        foreach ($quotes as $quote) {
            $quote->update([
                'is_recommended' => $quote->id === $recommendedId,
                'is_cheapest' => $quote->customer_price_cents === $cheapest,
                'is_fastest' => $quote->eta_days === $fastest,
            ]);
        }
    }

    private function meetsDate(TransportQuote $quote, mixed $requestedDate): bool
    {
        if ($requestedDate === null || trim((string) $requestedDate) === '') {
            return true;
        }

        return now()->startOfDay()->addDays($quote->eta_days)
            ->lte(CarbonImmutable::parse((string) $requestedDate)->endOfDay());
    }
}
