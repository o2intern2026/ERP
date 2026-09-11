<?php

namespace App\Modules\Transport\Services;

use App\Models\User;
use App\Modules\MasterData\Models\Carrier;
use App\Modules\MasterData\Models\Client;
use App\Modules\Transport\Models\CarrierService;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Support\TransportEnums;
use App\Support\Contracts\CarrierAdapter;
use App\Support\Contracts\ExceptionService;
use App\Support\Contracts\RateService;
use App\Support\Contracts\TransportOptionService as TransportOptionServiceContract;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * Aggregates the CarrierAdapter quotes into transport_quotes rows (contracts/services.md §3). CHANGE_REQUESTS #118: the client picks
 * its transport option with the 估价 at order time (orders.transport_preference) — estimate() prices that request with no shipment
 * behind it, the preliminary stage selects the matching quote as the client's own choice, and the final stage confirms it
 * automatically while the measured price stays within the variance tolerance; otherwise the client re-confirms in the portal.
 */
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

        $candidates = $this->candidates((int) $shipment->client_id, $request);
        if ($candidates->isEmpty()) {
            return $this->manualException($shipment);
        }

        $candidates = $this->markCandidates((int) $shipment->client_id, $candidates, $request);

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

            if ($stage === 'preliminary') {
                $this->applyClientPreference($shipment, $quotes);
            } else {
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
     * CHANGE_REQUESTS #118: price a quote request that has no shipment behind it yet (the portal's 获取估价 before the order exists).
     * Same adapters, same customer pricing and flags as quote(); nothing is written and no exception is raised — an empty list
     * means "no automatic option", which the page explains.
     */
    public function estimate(int $clientId, array $request): array
    {
        $candidates = $this->candidates($clientId, $request);
        if ($candidates->isEmpty()) {
            return [];
        }
        $names = Carrier::query()->whereIn('id', $candidates->pluck('carrier_id')->unique()->all())->pluck('name', 'id');

        return $this->markCandidates($clientId, $candidates, $request)->map(fn (array $c): array => [
            'carrier_id' => (int) $c['carrier_id'],
            'carrier_name' => $names[$c['carrier_id']] ?? null,
            'source' => (string) $c['source'],
            'service_level' => (string) $c['service_level'],
            'customer_price_cents' => (int) $c['customer_price_cents'],
            'eta_days' => $c['eta_days'] === null ? null : (int) $c['eta_days'],
            'is_recommended' => (bool) $c['is_recommended'],
            'is_cheapest' => (bool) $c['is_cheapest'],
            'is_fastest' => (bool) $c['is_fastest'],
        ])->values()->all();
    }

    /**
     * Every active carrier service's answer to the request, priced for the client (own fleet = fixed rate, third party = TR-DELIVERY-BASE
     * or the client's default markup on cost).
     *
     * @param  array<string, mixed>  $request
     * @return Collection<int, array<string, mixed>>
     */
    private function candidates(int $clientId, array $request): Collection
    {
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

                $candidates->push($this->candidate($clientId, $service, $option, $request));
            }
        }

        return $candidates;
    }

    /**
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $request
     */
    private function candidate(int $clientId, CarrierService $service, array $option, array $request): array
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
            [$customerPriceCents, $markupPercent] = $this->thirdPartyPrice($clientId, $service, $costCents);
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
                '_booking' => [
                    'service_code' => (string) $option['service_code'],
                    'quote_ref' => (string) ($option['raw']['booking_id'] ?? ''),
                    'pickup_dates' => array_values($option['pickup_dates'] ?? []),
                ],
                '_quote_request' => [
                    'zone' => $request['zone'] ?? '',
                    'items' => $request['items'] ?? [],
                    'receiver' => $request['receiver'] ?? [],
                ],
            ]),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @param  array<string, mixed>  $request
     * @return Collection<int, array<string, mixed>>
     */
    private function markCandidates(int $clientId, Collection $candidates, array $request): Collection
    {
        $cheapest = (int) $candidates->min('customer_price_cents');
        $fastest = (int) $candidates->min('eta_days');
        $eligible = $candidates->filter(
            fn (array $candidate): bool => $this->meetsRequestedDate($candidate, $request['requested_date'] ?? null)
        );
        $recommendedKey = null;

        if ($eligible->isNotEmpty()) {
            $lowestEligible = (int) $eligible->min('customer_price_cents');
            $thresholds = $this->rates->thresholds($clientId, 'TR-DELIVERY-BASE') ?? [];
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

    /**
     * CHANGE_REQUESTS #118: the preliminary quote matching what the client chose with the 估价 is selected at once — as the client's own
     * decision when the order was placed by one of its users — so the final stage can confirm it without anyone clicking.
     *
     * @param  Collection<int, TransportQuote>  $quotes
     */
    private function applyClientPreference(Shipment $shipment, Collection $quotes): void
    {
        $preference = $this->clientPreference($shipment);
        if ($preference === null || $this->selection === null) {
            return;
        }
        $match = $this->matching($quotes, $preference);
        if ($match === null) {
            return;
        }
        [$actor, $userId] = $this->preferenceActor($shipment, $preference);

        try {
            $this->selection->select($shipment->fresh(), $match, $actor, $userId);
        } catch (DomainException) {
            // Not selectable right now (status / expiry): a person picks on the shipment page or the client in the portal.
        }
    }

    /**
     * Final stage: the reference is what was committed to before — the selected preliminary quote, else the option the client chose
     * with the 估价. The final quote for the same option is confirmed automatically while its price stays within the client's
     * variance tolerance; a missing option or a larger difference sends the shipment back to `quoted` for the client (or a
     * coordinator on the client's behalf) to confirm.
     *
     * @param  Collection<int, TransportQuote>  $finalQuotes
     */
    private function resolveFinalVariance(Shipment $shipment, ?TransportQuote $preliminarySelection, Collection $finalQuotes): void
    {
        if ($preliminarySelection !== null) {
            $reference = [
                'source' => $preliminarySelection->source,
                'service_level' => $preliminarySelection->service_level,
                'carrier_id' => $preliminarySelection->carrier_id,
                'customer_price_cents' => (int) $preliminarySelection->customer_price_cents,
            ];
            [$actor, $userId] = $preliminarySelection->selected_by === 'client' && $preliminarySelection->selected_by_user_id
                ? ['client', (int) $preliminarySelection->selected_by_user_id]
                : ['system', null];
        } else {
            $reference = $this->clientPreference($shipment);
            if ($reference === null) {
                return; // nothing was committed to: the quotes wait for a person, as before
            }
            [$actor, $userId] = $this->preferenceActor($shipment, $reference);
        }

        $match = $this->matching($finalQuotes, $reference);
        if ($match === null || $this->selection === null) {
            $this->awaitReconfirmation($shipment);

            return;
        }

        $thresholds = $this->rates->thresholds($shipment->client_id, 'TR-DELIVERY-BASE') ?? [];
        $tolerance = max(0.0, (float) ($thresholds['variance_tolerance_percent'] ?? 10));
        $referencePrice = (int) ($reference['customer_price_cents'] ?? 0);
        $variance = $referencePrice > 0
            ? abs($match->customer_price_cents - $referencePrice) / $referencePrice * 100
            : INF;

        if ($variance > $tolerance) {
            $this->awaitReconfirmation($shipment);

            return;
        }

        if ($shipment->status === 'quote_confirmed') {
            $shipment->update(['status' => 'quoted']);
        }

        try {
            $this->selection->select($shipment->fresh(), $match, $actor, $userId);
        } catch (DomainException) {
            $this->awaitReconfirmation($shipment);
        }
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

    /**
     * The quote for the same option: exact carrier first, then the same source + service level.
     *
     * @param  Collection<int, TransportQuote>  $quotes
     * @param  array<string, mixed>  $reference
     */
    private function matching(Collection $quotes, array $reference): ?TransportQuote
    {
        $same = $quotes->filter(fn (TransportQuote $q): bool => $q->source === ($reference['source'] ?? null) && $q->service_level === ($reference['service_level'] ?? null));
        $carrierId = (int) ($reference['carrier_id'] ?? 0);

        return ($carrierId > 0 ? $same->first(fn (TransportQuote $q): bool => (int) $q->carrier_id === $carrierId) : null) ?? $same->first();
    }

    /** orders.transport_preference (Orders, X1) as the client left it with the 估价 — read-only, customer fields only. */
    private function clientPreference(Shipment $shipment): ?array
    {
        if (! Schema::hasColumn('orders', 'transport_preference')) {
            return null;
        }
        $raw = DB::table('orders')->where('id', $shipment->order_id)->value('transport_preference');
        $preference = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);

        return is_array($preference) && isset($preference['source'], $preference['service_level']) ? $preference : null;
    }

    /** The client's own user who chose (client actor) when it still belongs to the shipment's client, else the system. */
    private function preferenceActor(Shipment $shipment, array $preference): array
    {
        $user = isset($preference['chosen_by']) ? User::query()->find((int) $preference['chosen_by']) : null;
        if ($user !== null && $user->is_active && $user->isClientUser() && (int) $user->client_id === (int) $shipment->client_id) {
            return ['client', (int) $user->id];
        }

        return ['system', null];
    }

    /** @return array{int, float} */
    private function thirdPartyPrice(int $clientId, CarrierService $service, int $costCents): array
    {
        $priced = $this->rates->price($clientId, 'TR-DELIVERY-BASE', 1, [
            'carrier_id' => $service->carrier_id,
            'service_level' => $service->service_level,
            'cost_cents' => $costCents,
        ]);

        if (! $priced['missing_rate'] && ! $priced['is_poa'] && $priced['amount_cents'] !== null) {
            $price = (int) $priced['amount_cents'];

            return [$price, $costCents > 0 ? round((($price / $costCents) - 1) * 100, 2) : 0.0];
        }

        $markup = (float) (Client::query()->whereKey($clientId)->value('default_markup_percent') ?? 0);

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
