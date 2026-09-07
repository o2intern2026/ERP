<?php

namespace App\Support\Fakes;

use App\Support\Contracts\ManifestParser;

/** Ignores the file and returns two well-formed rows (same consignment mark, same address) plus no errors. */
final class FakeManifestParser implements ManifestParser
{
    public function parse(string $path): array
    {
        $common = [
            'consignment_mark' => 'FAKE-MARK-01',
            'deliver_to_name' => 'Amazon FBA BWU2',
            'deliver_to_phone' => null,
            'deliver_to_address' => '1 Warehouse Rd, Moorebank',
            'deliver_to_state' => 'NSW',
            'deliver_to_postcode' => '2170',
            'fba_reference' => 'FBA15FAKE01',
            'hs_code' => null, 'material' => null, 'usage' => null, 'brand' => null,
            'unit_price_cents' => 1000, 'unit_qty' => 10,
        ];

        return [
            'rows' => [
                $common + ['row' => 2, 'description_cn' => '塑料收纳盒', 'description_en' => 'Plastic storage box', 'package_type' => 'carton', 'carton_qty' => 20, 'total_price_cents' => 200000, 'actual_weight_kg' => 12.5, 'length_mm' => 600, 'width_mm' => 400, 'height_mm' => 400, 'cbm' => 0.096],
                $common + ['row' => 3, 'description_cn' => '金属置物架', 'description_en' => 'Metal shelf', 'package_type' => 'carton', 'carton_qty' => 5, 'total_price_cents' => 50000, 'actual_weight_kg' => 30.0, 'length_mm' => 1200, 'width_mm' => 400, 'height_mm' => 200, 'cbm' => 0.096],
            ],
            'errors' => [],
            'warnings' => [],
        ];
    }
}
