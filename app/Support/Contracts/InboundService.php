<?php

namespace App\Support\Contracts;

/**
 * Provided by Warehouse (seat C). contracts/services.md §10 — CHANGE_REQUESTS #117, #119.
 * The one way another module opens an ASN (预报单) from its own records: Orders' 从订单生成预报单 hands over the goods lines of the
 * client's orders; Warehouse creates the ASN and its lines under the Job the caller chose and reports the asn_line id per
 * order_line, so the caller writes its own side of the link (order_lines.asn_line_id). Mirror of OrderService::createFromAsn.
 * addOrderLinesToAsn() is the same hand-over onto an ASN that already exists (从订单导入货物行 on the ASN page, CR #119).
 */
interface InboundService
{
    /**
     * @param  array{client_id:int, warehouse_id:int, job_id:int, inbound_type:string, expected_date?:?string, notes?:?string, containers?:list<array{container_no:string, size:string, unpack_mode:string, gross_weight_kg?:?float}>}  $header
     * @param  list<array<string, mixed>>  $lines  asn_lines columns per goods line plus `order_line_id`; `container_no` attaches the line to one of the header's containers
     * @return array{asn_id:int, asn_no:string, lines:list<array{order_line_id:int, asn_line_id:int}>}
     */
    public function createAsnFromOrderLines(array $header, array $lines): array;

    /**
     * Append goods lines that come from orders to an existing ASN (CHANGE_REQUESTS #119). Same line shape as
     * createAsnFromOrderLines (asn_lines columns + `order_line_id`, optional `container_no`). Refused (RuleViolation
     * `warehouse.asns.errors.import_orders_closed`, replace `no`) unless the ASN status is booked / arrived / receiving.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{asn_id:int, asn_no:string, lines:list<array{order_line_id:int, asn_line_id:int}>}
     */
    public function addOrderLinesToAsn(int $asnId, array $lines): array;

    /**
     * 到仓方式 = 我方上门提货 on an ASN another module has just opened (CHANGE_REQUESTS #125: 待建预报 generates the ASN for a client's
     * portal collection request). Same rules and event as the ASN page (AsnService::setCollection, #124): row-locked, refused with a
     * RuleViolation (`warehouse.asns.collection.errors.*`) when the pickup address is incomplete, the ready date is past, nothing
     * can be priced (no packages and no goods line with weight + dims) or the warehouse has no address; publishes
     * `asn.collection_requested` in the caller's transaction. `client_preference` is whitelisted to the customer snapshot keys
     * (never cost / markup); when the key is absent the stored preference is kept.
     *
     * @param  array{address:array{name?:?string, phone?:?string, address:string, suburb:string, state:string, postcode:string, type?:?string}, ready_date:string, notes?:?string, packages?:list<array<string, mixed>>, client_preference?:?array<string, mixed>, requested_via?:?string, import_id?:?int}  $data
     * @return array{asn_id:int, collection_version:int}
     */
    public function requestCollection(int $asnId, array $data, ?int $actorId): array;
}
