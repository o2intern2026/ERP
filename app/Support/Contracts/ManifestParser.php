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
     * but different deliver_to / fba_reference" (ERP_PLAN §4.6 B2b). CHANGE_REQUESTS #126: `storage_tier` is null when the sheet has
     * no 存储等级 column, `standard` for an empty cell, `bottom` / `standard` for a recognised value; `storage_tier_declared` is true only
     * for a non-empty recognised cell (a declaration beats the value pre-fill of OrderImportService). CHANGE_REQUESTS #136:
     * `deliver_to_address_type` is null when the sheet has no 地址类型 / Address type column or the cell is empty (the caller keeps
     * its default), else `business` / `fba` / `residential`; every `errors` entry also carries `consignment_mark` (the refused
     * row's mark after carry-down, null when the mark itself is missing) so a caller can block that whole mark.
     *
     * @return array{rows:list<array{row:int, consignment_mark:string, description_cn:?string, description_en:?string, hs_code:?string, material:?string, usage:?string, brand:?string, package_type:?string, carton_qty:int, unit_qty:?int, unit_price_cents:?int, total_price_cents:?int, actual_weight_kg:?float, length_mm:?int, width_mm:?int, height_mm:?int, cbm:?float, deliver_to_name:?string, deliver_to_phone:?string, deliver_to_address:?string, deliver_to_state:?string, deliver_to_postcode:?string, deliver_to_address_type:?string, fba_reference:?string, storage_tier:?string, storage_tier_declared:bool}>, errors:list<array{row:int, column:string, message:string, consignment_mark:?string}>, warnings:list<array{row:int, column:string, message:string}>}
     */
    public function parse(string $path): array;

    /**
     * CHANGE_REQUESTS #128 (additive): rows typed on a form instead of read from a sheet — each posted row keyed by the canonical
     * field names above (`consignment_mark`, `description_cn`, …, `storage_tier`, `requested_date`), numbered 1..n by position, run
     * through exactly the same normalisation and validation as parse() (phone / postcode / state, package words, dims already in mm,
     * weight = line total, 存储等级 values, dates) with the same Chinese `第 N 行「列名」…` messages. Same return shape as parse().
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{rows:list<array<string, mixed>>, errors:list<array{row:int, column:string, message:string}>, warnings:list<array{row:int, column:string, message:string}>}
     */
    public function fromRows(array $rows): array;
}
