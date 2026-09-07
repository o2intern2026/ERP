<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\ChargeCode;
use App\Modules\Billing\Models\RateCard;
use App\Modules\Billing\Models\RateItem;
use App\Support\Contracts\RateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/** A5 pricing: standard card, client override, bands, minimums, POA, cost-plus, missing rate (never $0), thresholds (§6.8 #1, #5, #9, #10). */
class RateServiceTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_edward_standard_card_prices_every_uom(): void
    {
        $client = $this->client();
        $rates = app(RateService::class);

        $this->assertSame(28000, $rates->price($client->id, 'WH-DEVAN-40-PLT', 1)['amount_cents']);
        $this->assertSame(1350, $rates->price($client->id, 'WH-PUTAWAY-PLT', 3)['amount_cents']);
        $this->assertSame(450, $rates->price($client->id, 'WH-PICK-CTN-GE45', 1, ['weight_kg' => 50])['amount_cents']);
        $this->assertSame(350, $rates->price($client->id, 'WH-PICK-CTN-22-45', 1, ['weight_kg' => 30])['amount_cents']);
        $this->assertSame(150, $rates->price($client->id, 'WH-PICK-CTN-LT22', 1, ['weight_kg' => 10])['amount_cents']);
        $this->assertTrue($rates->price($client->id, 'WH-PICK-CTN-LT22', 1, ['weight_kg' => 30])['missing_rate']); // wrong band → no match
        $this->assertSame(8000, $rates->price($client->id, 'VAS-WASTE-CBM', 0.5)['amount_cents']); // minimum 1 CBM (§6.8 #1)
        $this->assertSame(1.0, $rates->price($client->id, 'VAS-WASTE-CBM', 0.5)['qty']);
        $this->assertSame(11000, $rates->price($client->id, 'VAS-LABOUR-HR-AH', 2)['amount_cents']);

        $poa = $rates->price($client->id, 'WH-DEVAN-40-MIXED', 1);
        $this->assertTrue($poa['is_poa']);
        $this->assertFalse($poa['missing_rate']);
        $this->assertSame(0, $poa['amount_cents']);

        $missing = $rates->price($client->id, 'TR-TAILGATE', 1);
        $this->assertTrue($missing['missing_rate']); // no Edward row: never $0 without a client card
        $this->assertSame(['max_line_count' => 20], $rates->thresholds($client->id, 'WH-DEVAN-20-LOOSE'));
    }

    public function test_client_card_overrides_the_standard_card_and_prices_cost_plus(): void
    {
        $client = $this->client(['default_markup_percent' => 20]);
        $rates = app(RateService::class);
        $card = RateCard::query()->create(['client_id' => $client->id, 'name' => 'Client special', 'version' => 1, 'effective_from' => today()->subDay(), 'status' => 'active']);
        $codes = ChargeCode::query()->pluck('id', 'code');
        RateItem::query()->create(['rate_card_id' => $card->id, 'charge_code_id' => $codes['WH-PUTAWAY-PLT'], 'pricing_mode' => 'fixed', 'rate_cents' => 600, 'min_charge_cents' => 2000]);
        RateItem::query()->create(['rate_card_id' => $card->id, 'charge_code_id' => $codes['TR-DELIVERY-BASE'], 'pricing_mode' => 'cost_plus']);
        RateItem::query()->create(['rate_card_id' => $card->id, 'charge_code_id' => $codes['TR-DELIVERY-BASE'], 'pricing_mode' => 'cost_plus', 'carrier_id' => null, 'service_level' => 'express', 'markup_percent' => 35]);
        RateItem::query()->create(['rate_card_id' => $card->id, 'charge_code_id' => $codes['TR-FUEL'], 'pricing_mode' => 'percent', 'markup_percent' => 12.5]);

        $putaway = $rates->price($client->id, 'WH-PUTAWAY-PLT', 2);
        $this->assertSame(2000, $putaway['amount_cents']); // 2 × 6.00 = 12.00 < minimum 20.00
        $this->assertTrue($putaway['min_charge_applied']);
        $this->assertSame('client', $putaway['calculation_snapshot']['card']);
        $this->assertSame(28000, $rates->price($client->id, 'WH-DEVAN-40-PLT', 1)['amount_cents']); // falls back to the standard card

        $this->assertSame(12000, $rates->price($client->id, 'TR-DELIVERY-BASE', 1, ['cost_cents' => 10000])['amount_cents']); // client default markup 20 %
        $this->assertSame(13500, $rates->price($client->id, 'TR-DELIVERY-BASE', 1, ['cost_cents' => 10000, 'service_level' => 'express'])['amount_cents']); // carrier / service override 35 %
        $this->assertSame(1500, $rates->price($client->id, 'TR-FUEL', 1, ['base_cents' => 12000])['amount_cents']); // 12.5 % of the delivery charge

        // Unbinding the standard card makes everything not on the client card a missing rate (§6.8 #9).
        $client->update(['standard_rate_card_id' => null]);
        $this->assertTrue($rates->price($client->id, 'WH-DEVAN-40-PLT', 1)['missing_rate']);
        $this->assertSame(2000, $rates->price($client->id, 'WH-PUTAWAY-PLT', 2)['amount_cents']);
    }

    public function test_pallet_class_suggestion_uses_the_card_thresholds(): void
    {
        $client = $this->client();
        $rates = app(RateService::class);

        $this->assertSame('standard', $rates->suggestPalletClass($client->id, 1200, 1200, 1400, 799));
        $this->assertSame('oversize_high', $rates->suggestPalletClass($client->id, 1200, 1200, 1600, 600));
        $this->assertSame('oversize_wide', $rates->suggestPalletClass($client->id, 2400, 1000, 1300, 500));
        $this->assertSame('overweight', $rates->suggestPalletClass($client->id, 1200, 1200, 1400, 900));
        $this->assertNull($rates->suggestPalletClass($client->id, 2600, 1200, 1300, 500));
    }
}
