<?php

namespace Tests\Support;

use App\Support\Contracts\CarrierAdapter;

/**
 * A carrier adapter for tests (CHANGE_REQUESTS #125): answers every quote request with one option at the configured cost for its
 * source and records the request, so a test can compare what the portal estimated with what Transport later quoted.
 */
final class StubCarrierAdapter implements CarrierAdapter
{
    /** @var array<string, int> carrier cost in cents per source — change it between steps to make a final quote drift */
    public static array $costs = [];

    /** @var list<array<string, mixed>> every carrier request received, oldest first */
    public static array $requests = [];

    public function __construct(private readonly string $source, private readonly string $level) {}

    public function source(): string
    {
        return $this->source;
    }

    public function capabilities(): array
    {
        return ['quote' => true, 'book' => true, 'cancel' => true, 'label' => false, 'tracking' => 'none', 'pod' => 'manual'];
    }

    public function quote(array $request): array
    {
        self::$requests[] = $request;

        return [[
            'service_code' => $this->source.'.'.$this->level, 'service_name' => $this->source.'.'.$this->level, 'service_level' => $this->level,
            'cost_cents' => self::$costs[$this->source] ?? 0, 'eta_days' => 1, 'pickup_dates' => [], 'raw' => ['pricing_mode' => $this->source === 'own_fleet' ? 'fixed' : 'cost_plus'],
        ]];
    }

    public function book(array $request, string $serviceCode, array $options = []): array
    {
        return ['booking_ref' => 'STUB-1', 'tracking_number' => null, 'label_path' => null, 'status' => 'booked', 'raw' => []];
    }

    public function cancel(string $bookingRef): bool
    {
        return true;
    }

    public function label(string $bookingRef): ?string
    {
        return null;
    }

    public function tracking(string $bookingRef): array
    {
        return [];
    }
}
