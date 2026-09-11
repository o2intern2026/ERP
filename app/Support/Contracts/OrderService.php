<?php

namespace App\Support\Contracts;

/**
 * Provided by Orders (seat X1, M3). contracts/services.md §2.
 * The single entry point for creating orders: manual, Excel, API, portal and ASN-generated orders all go through it.
 * awaitingAsn() / attachOrdersToAsn() (CHANGE_REQUESTS #119) let the ASN page import the client's pending orders as goods lines.
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

    /**
     * The client's orders whose goods still need an ASN (CHANGE_REQUESTS #119): from_stock orders in received / confirmed with
     * at least one goods line whose asn_line_id is null, oldest first. Read-only; what the ASN page's 从订单导入货物行 table lists.
     *
     * @return list<array{order_id:int, order_no:string, job_id:int, job_no:string, consignment_mark:?string, deliver_to_name:?string, deliver_to_suburb:?string, deliver_to_state:?string, requested_date:?string, operational_status:string, unlinked_lines:int, total_lines:int, unlinked_cartons:int}>
     */
    public function awaitingAsn(int $clientId): array;

    /**
     * Put the unlinked goods lines of the given orders onto an existing ASN (CHANGE_REQUESTS #119): Orders builds the lines
     * (consignee, mark, description, cartons from the order), hands them to InboundService::addOrderLinesToAsn(), writes
     * order_lines.asn_line_id, merges the orders into the ASN's Job (emptied per-order Jobs are cancelled) and, for confirmed
     * orders, sets qty_backordered so putaway allocates them. Refused (RuleViolation) for an order of another client
     * (`orders.inbound.errors.asn_other_client`), an ineligible order, an order whose old Job still has undelivered outbox
     * events (`orders.inbound.errors.events_pending`), or an ASN past receiving. `$containerNo` names the header container the
     * lines go onto — required by Warehouse when the ASN has more than one container, defaulted by it when there is exactly one.
     *
     * @param  list<int>  $orderIds
     * @return array{orders:int, lines:int, merged:list<string>, cancelled:list<string>, job_no:string} merged = order numbers moved into the ASN's Job; cancelled = emptied Job numbers
     */
    public function attachOrdersToAsn(int $asnId, array $orderIds, ?int $actorId, ?string $containerNo = null): array;
}
