<?php

namespace Tests\Support;

use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderCreationService;
use App\Modules\Orders\Services\OrderStatusService;
use App\Modules\Platform\Services\OutboxDispatcher;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\PutawayService;
use App\Modules\Warehouse\Services\ReceivingService;

/**
 * End-to-end scaffolding for outbound tests: real ASN → receive → putaway → real OMS order → confirm →
 * outbox round trips (Warehouse reserves, OMS builds fulfilments). Everything goes through the modules' own services.
 */
trait BuildsOutboundOrders
{
    /** @return array{asn: Asn, lines: array, units: array} */
    protected function stockedAsn(Client $client, Warehouse $warehouse, array $lineSpecs): array
    {
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container', 'containers' => [['container_no' => 'OUT-'.$asn_no = strtoupper(substr(md5(uniqid()), 0, 6)), 'size' => '40', 'unpack_mode' => 'loose']]]);
        $lines = app(AsnService::class)->addLines($asn, array_map(fn ($s) => ['container_no' => 'OUT-'.$asn_no, 'consignment_mark' => $s['mark'], 'description' => $s['description'] ?? 'Goods', 'expected_cartons' => $s['cartons'], 'weight_kg' => $s['weight_kg'] ?? null,
            'deliver_to_name' => $s['deliver_to_name'] ?? 'Receiver Pty Ltd', 'deliver_to_address' => $s['deliver_to_address'] ?? '1 Test St', 'deliver_to_suburb' => 'Melbourne', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000', 'fba_reference' => $s['fba'] ?? null], $lineSpecs));
        $units = [];
        foreach ($lines as $i => $line) {
            $spec = $lineSpecs[$i];
            $unitSpecs = $spec['units'] ?? [['unit_type' => 'carton', 'carton_qty' => $spec['cartons']]];
            $created = app(ReceivingService::class)->receiveLine($line, ['received_cartons' => $spec['cartons'], 'units' => $unitSpecs], $this->location($warehouse, 'receiving'));
            foreach ($created as $u) {
                app(PutawayService::class)->putaway($u, $this->location($warehouse, $spec['location_type'] ?? 'storage'));
                $units[] = $u->fresh();
            }
        }

        return ['asn' => $asn->fresh(), 'lines' => $lines, 'units' => $units];
    }

    /** Real OMS order on the ASN lines, confirmed; outbox runs twice so the fulfilment exists. */
    protected function confirmedOrder(Client $client, int $jobId, array $lineQtys, array $overrides = []): Order
    {
        $order = app(OrderCreationService::class)->create(array_replace([
            'client_id' => $client->id, 'job_id' => $jobId, 'order_type' => 'from_stock', 'external_ref' => 'OUT-'.uniqid(),
            'deliver_to_name' => 'Receiver Pty Ltd', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Melbourne', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000',
            'deliver_to_address_type' => 'business', 'requested_date' => today()->addDay()->toDateString(), 'service_level' => 'standard',
            'lines' => array_map(fn ($l) => ['description_en' => 'Goods', 'package_type' => 'carton', 'carton_qty' => $l['qty'], 'asn_line_id' => $l['asn_line_id'], 'actual_weight_kg' => $l['weight_kg'] ?? null], $lineQtys),
        ], $overrides), null, 'manual');
        app(OrderStatusService::class)->transitionOperational($order, 'confirmed');
        app(OutboxDispatcher::class)->dispatchDue(); // order.confirmed → Warehouse reserves → stock.reserved
        app(OutboxDispatcher::class)->dispatchDue(); // stock.reserved → OMS fulfilment batches

        return $order->fresh();
    }
}
