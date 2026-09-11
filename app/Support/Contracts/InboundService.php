<?php

namespace App\Support\Contracts;

/**
 * Provided by Warehouse (seat C). contracts/services.md §10 — CHANGE_REQUESTS #117.
 * The one way another module opens an ASN (预报单) from its own records: Orders' 从订单生成预报单 hands over the goods lines of the
 * client's orders; Warehouse creates the ASN and its lines under the Job the caller chose and reports the asn_line id per
 * order_line, so the caller writes its own side of the link (order_lines.asn_line_id). Mirror of OrderService::createFromAsn.
 */
interface InboundService
{
    /**
     * @param  array{client_id:int, warehouse_id:int, job_id:int, inbound_type:string, expected_date?:?string, notes?:?string, containers?:list<array{container_no:string, size:string, unpack_mode:string, gross_weight_kg?:?float}>}  $header
     * @param  list<array<string, mixed>>  $lines  asn_lines columns per goods line plus `order_line_id`; `container_no` attaches the line to one of the header's containers
     * @return array{asn_id:int, asn_no:string, lines:list<array{order_line_id:int, asn_line_id:int}>}
     */
    public function createAsnFromOrderLines(array $header, array $lines): array;
}
