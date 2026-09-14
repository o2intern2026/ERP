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
        if ($shipment->isCollection()) {
            return $this->forCollection($shipment);
        }
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

    /**
     * 我方上门提货 (CHANGE_REQUESTS #124): nothing was packed by us — the manifest is the 预报单's declared packages, one row per piece,
     * labelled `{shipment_no}-{n}` so the consignment note and the own-fleet labels have a barcode per piece.
     *
     * @return list<array{id:int, package_type:string, weight_kg:string, length_mm:int, width_mm:int, height_mm:int, carton_label:string}>
     */
    private function forCollection(Shipment $shipment): array
    {
        if (! Schema::hasTable('asns') || $shipment->asn_id === null) {
            return [];
        }
        $asn = DB::table('asns')->where('id', $shipment->asn_id)->first(['id', 'collection_packages']);
        if ($asn === null) {
            return [];
        }

        $rows = [];
        $n = 0;
        foreach (ShipmentQuoteRequestFactory::collectionItems($asn) as $item) {
            for ($i = 0; $i < $item['qty']; $i++) {
                $n++;
                $rows[] = [
                    'id' => $n,
                    'package_type' => (string) $item['description'],
                    'weight_kg' => number_format((float) $item['weight_kg'], 3, '.', ''),
                    'length_mm' => (int) $item['length_mm'],
                    'width_mm' => (int) $item['width_mm'],
                    'height_mm' => (int) $item['height_mm'],
                    'carton_label' => $shipment->shipment_no.'-'.$n,
                ];
            }
        }

        return $rows;
    }
}
