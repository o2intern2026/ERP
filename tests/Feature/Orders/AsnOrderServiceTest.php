<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\AsnOrderService;
use App\Modules\Warehouse\Services\AsnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** A4 OrderService contract: received ASN lines use the same creation path and are idempotent. */
class AsnOrderServiceTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_received_asn_lines_are_grouped_and_a_second_call_creates_nothing(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create([
            'client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck',
            'expected_date' => '2026-09-30',
        ]);
        $lines = app(AsnService::class)->addLines($asn, [
            $this->line('ASN-MARK', '1 Warehouse Way', 'FBA-ONE', 2),
            $this->line('ASN-MARK', '1 Warehouse Way', 'FBA-ONE', 3),
            $this->line('BLOCKED', '2 First Road', 'FBA-A', 1),
            $this->line('BLOCKED', '3 Second Road', 'FBA-B', 1),
        ]);

        $service = app(AsnOrderService::class);
        $first = $service->createFromAsn($asn->id);

        $this->assertCount(1, $first['orders']);
        $this->assertSame([$lines[0]->id, $lines[1]->id], $first['orders'][0]['asn_line_ids']);
        $this->assertSame('inconsistent_delivery_or_fba', $first['blocked'][0]['reason']);
        $order = Order::query()->with('lines')->sole();
        $this->assertSame('manual', $order->source);
        $this->assertSame($asn->job_id, $order->job_id);
        $this->assertCount(2, $order->lines);

        $second = $service->createFromAsn($asn->id);
        $this->assertSame([], $second['orders']);
        $this->assertSame(1, Order::query()->count());
    }

    /** @return array<string,mixed> */
    private function line(string $mark, string $address, string $fba, int $cartons): array
    {
        return [
            'consignment_mark' => $mark, 'deliver_to_name' => 'Amazon Receiving', 'deliver_to_phone' => '0299999999',
            'deliver_to_address' => $address, 'deliver_to_suburb' => 'Kemps Creek', 'deliver_to_state' => 'NSW',
            'deliver_to_postcode' => '2178', 'fba_reference' => $fba, 'description' => 'Display stand',
            'package_type' => 'carton', 'expected_cartons' => $cartons, 'received_cartons' => $cartons,
        ];
    }
}
