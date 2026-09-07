<?php

namespace App\Modules\Transport\Services;

use App\Modules\Transport\Models\CarrierService;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Support\TransportEnums;
use App\Support\Contracts\CarrierAdapter;
use App\Support\Contracts\ExceptionService;
use App\Support\Contracts\RateService;
use App\Support\Contracts\TransportOptionService as TransportOptionServiceContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class TransportOptionService implements TransportOptionServiceContract
{
    /** @var array<string, CarrierAdapter> */
    private array $adapters = [];

    /** @param iterable<CarrierAdapter> $adapters */
    public function __construct(
        iterable $adapters,
        private readonly ShipmentQuoteRequestFactory $requests,
        private readonly RateService $rates,
        private readonly ExceptionService $exceptions,
        private readonly ?QuoteSelectionService $selection = null,
    ) {
        foreach ($adapters as $adapter) {
            $this->adapters[$adapter->source()] = $adapter;
        }
    }

    public function quote(int $shipmentId, string $stage): array
    {
        if (! in_array($stage, TransportEnums::QUOTE_STAGES, true)) {
            throw new InvalidArgumentException("Unknown quote stage: {$stage}");
        }

        $shipment = Shipment::query()->with('client')->findOrFail($shipmentId);
        $request = $this->requests->build($shipment, $stage);
        if ($request === null) {
            return $this->manualException($shipment);
        }

        $services = CarrierService::query()
            ->where('active', true)
            ->orderBy('id')
            ->get()
            ->groupBy('source');

        $candidates = collect();
        foreach ($services as $source => $sourceServices) {
            $adapter = $this->adapters[$source] ?? null;
            if ($adapter === null || ! $adapter->capabilities()['quote']) {
                continue;
            }

            foreach ($adapter->quote($request) as $option) {
                $service = $sourceServices->firstWhere('service_level', $option['service_level']);
                if ($service === null || ($option['eta_days'] === null && $service->default_eta_days === null)) {
                    continue;
                }

                $candidates->push($this->candidate($shipment, $service, $option, $request));
            }
        }

        if ($candidates->isEmpty()) {
            return $this->manualException($shipment);
        }

        $candidates = $this->markCandidates($shipment, $candidates, $request);

        return DB::transaction(function () use ($shipment, $stage, $candidates): array {
            $preliminarySelection = $stage === 'final'
                ? TransportQuote::query()
                    ->where('shipment_id', $shipment->id)
                    ->where('quote_stage', 'preliminary')
                    ->where('status', 'selected')
                    ->latest('id')
                    ->first()
                : null;

            TransportQuote::query()
                ->where('shipment_id', $shipment->id)
                ->where('quote_stage', $stage)
                ->where('status', 'quoted')
                ->update(['status' => 'requoted']);

            $now = now();
            $quotes = $candidates->map(function (array $candidate) use ($shipment, $stage, $now): TransportQuote {
                return TransportQuote::query()->create($candidate + [
                    'shipment_id' => $shipment->id,
                    'quote_stage' => $stage,
                    'status' => 'quoted',
                    'is_recommended' => false,
                    'is_cheapest' => false,
                    'is_fastest' => false,
                    'quoted_at' => $now,
                    'expires_at' => $now->copy()->addDay(),
                ]);
            });

            if ($shipment->status === 'quoting') {
                $shipment->update(['status' => 'quoted']);
            }

            if ($stage === 'final' && $preliminarySelection !== null) {
                $this->resolveFinalVariance($shipment, $preliminarySelection, $quotes);
            }

            return $quotes->map(fn (TransportQuote $quote): array => [
                'transport_quote_id' => $quote->id,
                'carrier_id' => $quote->carrier_id,
                'source' => $quote->source,
                'service_level' => $quote->service_level,
                'cost_cents' => $quote->cost_cents,
                'customer_price_cents' => $quote->customer_price_cents,
                'eta_days' => $quote->eta_days,
                'is_recommended' => $quote->is_recommended,
                'is_cheapest' => $quote->is_cheapest,
                'is_fastest' => $quote->is_fastest,
                'quoted_at' => $quote->quoted_at->toIso8601String(),
                'expires_at' => $quote->expires_at->toIso8601String(),
            ])->all();
        });
    }

    /**
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $request
     */
    private function candidate(Shipment $shipment, CarrierService $service, array $option, array $request): array
    {
        $costCents = (int) $option['cost_cents'];
        $customerPriceCents = $costCents;
        $markupPercent = null;

        if ($service->source === 'manual' && isset($option['raw']['customer_price_cents'])) {
            $customerPriceCents = (int) $option['raw']['customer_price_cents'];
            $markupPercent = $costCents > 0
                ? round((($customerPriceCents / $costCents) - 1) * 100, 2)
                : null;
        } elseif ($service->source !== 'own_fleet') {
            [$customerPriceCents, $markupPercent] = $this->thirdPartyPrice($shipment, $service, $costCents);
        }

        return [
            'carrier_id' => $service->carrier_id,
            'source' => $service->source,
            'service_level' => $option['service_level'],
            'cost_cents' => $costCents,
            'customer_price_cents' => $customerPriceCents,
            'markup_percent' => $markupPercent,
            'eta_days' => $option['eta_days'] ?? $service->default_eta_days,
            'raw_response' => array_replace($option['raw'], [
                '_quote_request' => [
                    'zone' => $request['zone'] ?? '',
                    'items' => $request['items'] ?? [],
                ],
            ]),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @param  array<string, mixed>  $request
     * @return Collection<int, array<string, mixed>>
     */
    private function markCandidates(Shipment $shipment, Collection $candidates, array $request): Collection
    {
        $cheapest = (int) $candidates->min('customer_price_cents');
        $fastest = (int) $candidates->min('eta_days');
        $eligible = $candidates->filter(
            fn (array $candidate): bool => $this->meetsRequestedDate($candidate, $request['requested_date'] ?? null)
        );
        $recommendedKey = null;

        if ($eligible->isNotEmpty()) {
            $lowestEligible = (int) $eligible->min('customer_price_cents');
            $thresholds = $this->rates->thresholds($shipment->client_id, 'TR-DELIVERY-BASE') ?? [];
            $preference = max(0.0, (float) ($thresholds['own_fleet_preference_percent'] ?? 0));
            $ownFleetLimit = (int) floor($lowestEligible * (1 + $preference / 100));
            $preferredOwnFleet = $eligible
                ->filter(fn (array $candidate): bool => $candidate['source'] === 'own_fleet'
                    && $candidate['customer_price_cents'] <= $ownFleetLimit)
                ->sortBy([
                    ['customer_price_cents', 'asc'],
                    ['eta_days', 'asc'],
                ]);

            $recommendedKey = $preferredOwnFleet->keys()->first()
                ?? $eligible->sortBy([
                    ['customer_price_cents', 'asc'],
                    ['eta_days', 'asc'],
                ])->keys()->first();
        }

        return $candidates->map(fn (array $candidate, int $key): array => $candidate + [
            'is_recommended' => $key === $recommendedKey,
            'is_cheapest' => $candidate['customer_price_cents'] === $cheapest,
            'is_fastest' => $candidate['eta_days'] === $fastest,
        ]);
    }

    /** @param array<string, mixed> $candidate */
    private function meetsRequestedDate(array $candidate, mixed $requestedDate): bool
    {
        if ($requestedDate === null || trim((string) $requestedDate) === '') {
            return true;
        }

        try {
            $deadline = CarbonImmutable::parse((string) $requestedDate)->endOfDay();

            return now()->startOfDay()->addDays((int) $candidate['eta_days'])->lte($deadline);
        } catch (Throwable) {
            return false;
        }
    }

    /** @param Collection<int, TransportQuote> $finalQuotes */
    private function resolveFinalVariance(
        Shipment $shipment,
        TransportQuote $preliminarySelection,
        Collection $finalQuotes,
    ): void {
        $recommended = $finalQuotes->firstWhere('is_recommended', true);
        if ($recommended === null) {
            $this->awaitReconfirmation($shipment);

            return;
        }

        $thresholds = $this->rates->thresholds($shipment->client_id, 'TR-DELIVERY-BASE') ?? [];
        $tolerance = max(0.0, (float) ($thresholds['variance_tolerance_percent'] ?? 10));
        $preliminaryPrice = $preliminarySelection->customer_price_cents;
        $variance = $preliminaryPrice > 0
            ? abs($recommended->customer_price_cents - $preliminaryPrice) / $preliminaryPrice * 100
            : INF;

        if ($variance > $tolerance || $this->selection === null) {
            $this->awaitReconfirmation($shipment);

            return;
        }

        if ($shipment->status === 'quote_confirmed') {
            $shipment->update(['status' => 'quoted']);
        }

        $this->selection->select($shipment->fresh(), $recommended, 'system');
    }

    private function awaitReconfirmation(Shipment $shipment): void
    {
        $shipment->update([
            'status' => 'quoted',
            'selected_quote_id' => null,
            'carrier_id' => null,
            'service_level' => null,
        ]);
    }

    /** @return array{int, float} */
    private function thirdPartyPrice(Shipment $shipment, CarrierService $service, int $costCents): array
    {
        $priced = $this->rates->price($shipment->client_id, 'TR-DELIVERY-BASE', 1, [
            'carrier_id' => $service->carrier_id,
            'service_level' => $service->service_level,
            'cost_cents' => $costCents,
        ]);

        if (! $priced['missing_rate'] && ! $priced['is_poa'] && $priced['amount_cents'] !== null) {
            $price = (int) $priced['amount_cents'];

            return [$price, round((($price / $costCents) - 1) * 100, 2)];
        }

        $markup = (float) $shipment->client->default_markup_percent;

        return [
            (int) round($costCents * (1 + $markup / 100), 0, PHP_ROUND_HALF_UP),
            $markup,
        ];
    }

    private function manualException(Shipment $shipment): array
    {
        $this->exceptions->raise('manual_transport', 'transport', [
            'job_id' => $shipment->job_id,
            'client_id' => $shipment->client_id,
            'order_id' => $shipment->order_id,
            'source_type' => 'shipment',
            'source_id' => $shipment->id,
            'message' => __('transport.exceptions.no_options'),
        ]);

        return [];
    }
}
