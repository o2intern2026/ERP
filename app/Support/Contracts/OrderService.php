<?php

namespace App\Support\Contracts;

/**
 * Provided by Orders (seat X1, M3). contracts/services.md §2.
 * The single entry point for creating orders: manual, Excel, API, portal and ASN-generated orders all go through it.
 */
interface OrderService
{
    /**
     * Create delivery orders from an ASN's putaway lines, grouped by consignment_mark + deliver_to + fba_reference
     * (ERP_PLAN §4.6 B2c). Lines already generated are skipped; a group whose address or FBA reference is
     * inconsistent is returned in `blocked` and needs manual confirmation. Orders join the ASN's Job.
     *
     * @return array{orders:list<array{order_id:int, order_no:string, asn_line_ids:list<int>}>, blocked:list<array{consignment_mark:string, reason:string, asn_line_ids:list<int>}>}
     */
    public function createFromAsn(int $asnId, string $groupingKey = 'mark_address_fba'): array;
}
