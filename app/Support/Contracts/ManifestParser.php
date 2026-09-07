<?php

namespace App\Support\Contracts;

/**
 * Parser for the real 《需派送货物清单》 spreadsheet, shared by ASN import (B2b, Warehouse) and order import
 * (A4, Orders). Provided by Orders (seat X1, M3); Warehouse uses the Fake until then. contracts/services.md §8.
 */
interface ManifestParser
{
    /**
     * One row per goods line; money in cents, dimensions in mm, weights in kg. Rows with hard errors are
     * excluded from `rows` and listed in `errors`; `warnings` includes the pre-check "same consignment_mark
     * but different deliver_to / fba_reference" (ERP_PLAN §4.6 B2b).
     *
     * @return array{rows:list<array{row:int, consignment_mark:string, description_cn:?string, description_en:?string, hs_code:?string, material:?string, usage:?string, brand:?string, package_type:?string, carton_qty:int, unit_qty:?int, unit_price_cents:?int, total_price_cents:?int, actual_weight_kg:?float, length_mm:?int, width_mm:?int, height_mm:?int, cbm:?float, deliver_to_name:?string, deliver_to_phone:?string, deliver_to_address:?string, deliver_to_state:?string, deliver_to_postcode:?string, fba_reference:?string}>, errors:list<array{row:int, column:string, message:string}>, warnings:list<array{row:int, column:string, message:string}>}
     */
    public function parse(string $path): array;
}
