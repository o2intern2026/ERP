<?php

namespace Tests\Feature\Platform;

use App\Support\PackageUnits;
use App\Support\UrgentDespatch;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** CHANGE_REQUESTS #120: the two helpers Orders, Transport and Billing share for pickup_deliver handling charges. */
class SupportHelpersTest extends TestCase
{
    public function test_pallet_and_skid_are_pallets_and_every_other_declared_type_is_a_carton(): void
    {
        $this->assertSame('pallet', PackageUnits::unitType('pallet'));
        $this->assertSame('pallet', PackageUnits::unitType('Pallet'));
        $this->assertSame('pallet', PackageUnits::unitType(' SKID '));
        $this->assertSame('carton', PackageUnits::unitType('carton'));
        $this->assertSame('carton', PackageUnits::unitType('satchel'));
        $this->assertSame('carton', PackageUnits::unitType('Box'));
        $this->assertSame('carton', PackageUnits::unitType(''));
        $this->assertSame('carton', PackageUnits::unitType(null));
        $this->assertSame('carton', PackageUnits::unitType('palletised')); // exact type only, not a prefix match
    }

    public function test_urgent_only_when_requested_today_and_confirmed_after_the_client_cutoff(): void
    {
        $at = new DateTimeImmutable('2026-09-14 17:30:00');

        $this->assertTrue(UrgentDespatch::isUrgent('2026-09-14', '17:00', $at));
        $this->assertTrue(UrgentDespatch::isUrgent('2026-09-14', '17:00:00', $at));
        $this->assertTrue(UrgentDespatch::isUrgent('2026-09-14', '17:29:59', $at));
        $this->assertTrue(UrgentDespatch::isUrgent('2026-09-14 00:00:00', '17:00', $at)); // a date-time string is read by its date
        $this->assertTrue(UrgentDespatch::isUrgent('2026-09-14', '17:00', Carbon::parse('2026-09-14 17:30:00'))); // Carbon is a DateTimeInterface too

        $this->assertFalse(UrgentDespatch::isUrgent('2026-09-14', '17:30:00', $at)); // exactly at the cut-off is not after it
        $this->assertFalse(UrgentDespatch::isUrgent('2026-09-14', '17:30', $at));
        $this->assertFalse(UrgentDespatch::isUrgent('2026-09-14', '18:00', $at));
        $this->assertFalse(UrgentDespatch::isUrgent('2026-09-15', '17:00', $at)); // tomorrow: never urgent
        $this->assertFalse(UrgentDespatch::isUrgent('2026-09-13', '17:00', $at)); // yesterday: late, not urgent
        $this->assertFalse(UrgentDespatch::isUrgent(null, '17:00', $at));
        $this->assertFalse(UrgentDespatch::isUrgent('', '17:00', $at));
        $this->assertFalse(UrgentDespatch::isUrgent('2026-09-14', null, $at)); // no client cut-off configured
        $this->assertFalse(UrgentDespatch::isUrgent('2026-09-14', '  ', $at));
        $this->assertFalse(UrgentDespatch::isUrgent('2026-09-14', '17:00', new DateTimeImmutable('2026-09-14 09:00:00')));
    }
}
