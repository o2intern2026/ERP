<?php

namespace App\Modules\Transport\Services;

use App\Modules\Transport\Models\Shipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only projection of the Warehouse packages contract for a consignment note. */
class PackageManifest
{
    /** @return list<array{id:int, package_type:string, weight_kg:string, length_mm:int, width_mm:int, height_mm:int, carton_label:string}> */
    public function forShipment(Shipment $shipment): array
    {
        if (! Schema::hasTable('packages')) {
            return [];
        }

        $query = DB::table('packages')
            ->select(['id', 'package_type', 'weight_kg', 'length_mm', 'width_mm', 'height_mm', 'carton_label'])
            ->where('client_id', $shipment->client_id);

        if ($shipment->fulfilment_id !== null) {
            $query->where('fulfilment_id', $shipment->fulfilment_id);
        } else {
            $query->where('order_id', $shipment->order_id);
        }

        return $query->orderBy('id')->get()->map(fn (object $package): array => (array) $package)->all();
    }
}
