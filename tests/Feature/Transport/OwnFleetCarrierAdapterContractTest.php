<?php

namespace Tests\Feature\Transport;

use App\Modules\Transport\Adapters\OwnFleetCarrierAdapter;
use App\Support\Contracts\CarrierAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CarrierAdapterTestCase;
use Tests\Support\CreatesUsers;

/** CHANGE_REQUESTS #163: the own-fleet adapter keeps the CarrierAdapter contract (prices from the rate card, no HTTP). */
class OwnFleetCarrierAdapterContractTest extends CarrierAdapterTestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function adapter(): CarrierAdapter
    {
        return app(OwnFleetCarrierAdapter::class);
    }

    protected function request(): array
    {
        return ['client_id' => $this->client()->id] + parent::request();
    }
}
