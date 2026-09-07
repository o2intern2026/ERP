<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Orders\Models\Order;
use App\Modules\Warehouse\Services\AsnOrderGeneration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** B2c through the real OMS OrderService (§4.7 #17 #18): one order per mark, lines linked back, second click creates nothing. */
class AsnOrderGenerationTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_orders_are_generated_once_per_consignment_mark_and_linked_back_to_the_asn_lines(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        ['asn' => $asn, 'lines' => $lines] = $this->stockedAsn($client, $warehouse, [
            ['mark' => 'GD20260506BC', 'cartons' => 3, 'fba' => 'FBA15X1', 'deliver_to_address' => '10 Amazon Way'],
            ['mark' => 'GD20260506BC', 'cartons' => 2, 'fba' => 'FBA15X1', 'deliver_to_address' => '10 Amazon Way'],
            ['mark' => 'JJ26051603', 'cartons' => 4, 'deliver_to_name' => 'Lighting Co', 'deliver_to_address' => '5 Lamp Rd'],
        ]);
        $this->assertSame('putaway', $asn->status);

        $result = app(AsnOrderGeneration::class)->generate($asn);

        $this->assertCount(2, $result['orders'], json_encode($result)); // §4.7 #17: orders = marks
        $this->assertSame(3, $result['linked_lines']);
        $this->assertSame([], $result['blocked']);
        $orders = Order::query()->with('lines')->orderBy('id')->get();
        $this->assertCount(2, $orders);
        $this->assertSame($asn->job_id, $orders[0]->job_id);
        $this->assertSame([3, 2], $orders->firstWhere('consignment_mark', 'GD20260506BC')->lines->pluck('carton_qty')->all());
        $this->assertSame('fba', $orders->firstWhere('consignment_mark', 'GD20260506BC')->deliver_to_address_type);
        foreach ($lines as $line) {
            $this->assertNotNull($line->fresh()->order_line_id, 'every ASN line points at its order line');
        }
        $this->assertDatabaseHas('order_lines', ['asn_line_id' => $lines[2]->id, 'carton_qty' => 4]);

        $again = app(AsnOrderGeneration::class)->generate($asn->fresh());
        $this->assertSame([], $again['orders']); // §4.7 #18: no duplicates
        $this->assertSame(2, Order::query()->count());
    }
}
