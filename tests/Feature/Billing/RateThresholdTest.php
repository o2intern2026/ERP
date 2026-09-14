<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\ChargeCode;
use App\Modules\Billing\Models\RateCard;
use App\Modules\Billing\Models\RateItem;
use App\Modules\Billing\Services\RateService;
use App\Support\Fakes\FakeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * contracts/services.md §4 in the REAL RateService (CHANGE_REQUESTS #122 closed the drift with the Fake): the 20-line devanning
 * cap and the 22.5 t cartage cap on the rate item flip the line to POA; an allocated fraction of a box skips minimum quantity
 * and minimum charge.
 */
class RateThresholdTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_line_count_and_gross_weight_caps_on_the_rate_item_make_the_line_poa(): void
    {
        $client = $this->client();
        $rates = app(RateService::class);
        $this->assertInstanceOf(RateService::class, $rates);

        $over = $rates->price($client->id, 'WH-DEVAN-20-LOOSE', 1, ['line_count' => 25]);
        $this->assertTrue($over['is_poa']);
        $this->assertFalse($over['missing_rate']);
        $this->assertSame([0, 'max_line_count'], [$over['amount_cents'], $over['calculation_snapshot']['reason']]);
        $this->assertSame(40000, $rates->price($client->id, 'WH-DEVAN-20-LOOSE', 1, ['line_count' => 20])['amount_cents']); // within
        $this->assertSame(40000, $rates->price($client->id, 'WH-DEVAN-20-LOOSE', 1)['amount_cents']); // unknown line count → priced (never invented)
        $this->assertSame(18000, $rates->price($client->id, 'WH-DEVAN-20-PLT', 1, ['line_count' => 60])['amount_cents']); // no cap on the pallet row

        $heavy = $rates->price($client->id, 'TR-CARTAGE-20', 1, ['gross_weight_kg' => 23000]);
        $this->assertSame([true, 0, 'max_gross_weight_kg'], [$heavy['is_poa'], $heavy['amount_cents'], $heavy['calculation_snapshot']['reason']]);
        $this->assertSame(123060, $rates->price($client->id, 'TR-CARTAGE-20', 1, ['gross_weight_kg' => 22500])['amount_cents']); // at the cap → priced
        $this->assertSame(129160, $rates->price($client->id, 'TR-CARTAGE-40', 1, ['gross_weight_kg' => 22000])['amount_cents']);

        // The Fake agrees with the real service on the same inputs (contracts/services.md "Fakes: rules of use").
        $fake = new FakeRateService;
        $this->assertTrue($fake->price(1, 'WH-DEVAN-20-LOOSE', 1, ['line_count' => 25])['is_poa']);
        $this->assertTrue($fake->price(1, 'TR-CARTAGE-20', 1, ['gross_weight_kg' => 23000])['is_poa']);
    }

    public function test_an_allocated_fraction_skips_minimum_quantity_and_minimum_charge(): void
    {
        $client = $this->client();
        $rates = app(RateService::class);

        $this->assertSame([8000, 1.0], [$rates->price($client->id, 'VAS-WASTE-CBM', 0.5)['amount_cents'], $rates->price($client->id, 'VAS-WASTE-CBM', 0.5)['qty']]); // whole: minimum 1 CBM
        $share = $rates->price($client->id, 'VAS-WASTE-CBM', 0.5, ['allocated' => true]);
        $this->assertSame([4000, 0.5, false, true], [$share['amount_cents'], $share['qty'], $share['min_charge_applied'], $share['calculation_snapshot']['allocated']]);
        $this->assertSame(27500, $rates->price($client->id, 'WH-DEVAN-40-LOOSE', 0.5, ['allocated' => true, 'line_count' => 15])['amount_cents']);
        $this->assertTrue($rates->price($client->id, 'WH-DEVAN-40-LOOSE', 0.5, ['allocated' => true, 'line_count' => 21])['is_poa']); // the cap still applies to the whole box

        $card = RateCard::query()->create(['client_id' => $client->id, 'name' => 'minimums', 'version' => 1, 'effective_from' => today()->subDay(), 'status' => 'active']);
        RateItem::query()->create(['rate_card_id' => $card->id, 'charge_code_id' => ChargeCode::query()->where('code', 'WH-DEVAN-40-LOOSE')->value('id'), 'pricing_mode' => 'fixed', 'rate_cents' => 60000, 'min_charge_cents' => 50000, 'threshold_json' => ['min_billable_qty' => 1, 'max_line_count' => 20]]);
        $whole = $rates->price($client->id, 'WH-DEVAN-40-LOOSE', 0.25);
        $this->assertSame([60000, 1.0, false], [$whole['amount_cents'], $whole['qty'], $whole['min_charge_applied']]); // floored to 1 box
        $fraction = $rates->price($client->id, 'WH-DEVAN-40-LOOSE', 0.25, ['allocated' => true]);
        $this->assertSame([15000, 0.25, false, 'client'], [$fraction['amount_cents'], $fraction['qty'], $fraction['min_charge_applied'], $fraction['calculation_snapshot']['card']]);

        $fake = new FakeRateService;
        $this->assertSame(3200, $fake->price(1, 'VAS-WASTE-CBM', 0.4, ['allocated' => true])['amount_cents']);
        $this->assertSame(8000, $fake->price(1, 'VAS-WASTE-CBM', 0.4)['amount_cents']);
    }
}
