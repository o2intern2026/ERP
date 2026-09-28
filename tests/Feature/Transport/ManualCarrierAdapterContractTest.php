<?php

namespace Tests\Feature\Transport;

use App\Modules\Transport\Adapters\ManualCarrierAdapter;
use App\Support\Contracts\CarrierAdapter;
use Tests\Support\CarrierAdapterTestCase;

/** CHANGE_REQUESTS #163: the built-in manual adapter keeps the CarrierAdapter contract (no HTTP — the hooks stay empty). */
class ManualCarrierAdapterContractTest extends CarrierAdapterTestCase
{
    protected function adapter(): CarrierAdapter
    {
        return app(ManualCarrierAdapter::class);
    }

    protected function request(): array
    {
        return parent::request() + ['manual_quotes' => [['service_code' => 'manual', 'service_name' => 'Quoted by phone', 'service_level' => 'standard', 'cost_cents' => 15000, 'eta_days' => 2, 'pickup_dates' => ['2026-09-08'], 'raw' => ['customer_price_cents' => 18500]]]];
    }
}
