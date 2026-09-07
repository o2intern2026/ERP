<?php

namespace App\Support;

/**
 * The enumerations of contracts/enums.md that code needs to validate against. Values are law: change the contract
 * first, then this file. Modules add their own module-local lists in their own namespace, never here.
 */
final class Enums
{
    public const ROLES = ['admin', 'customer_service', 'dispatcher', 'warehouse_supervisor', 'warehouse_operator', 'transport_operator', 'finance', 'client'];

    public const JOB_TYPES = ['container', 'loose', 'transport_only', 'return'];

    public const JOB_OPERATIONAL_STATUSES = ['open', 'receiving', 'in_stock', 'dispatching', 'completed', 'cancelled'];

    public const JOB_REVENUE_STATUSES = ['unbilled', 'partially_invoiced', 'invoiced', 'paid'];

    public const JOB_COST_STATUSES = ['estimated', 'partially_confirmed', 'confirmed'];

    public const EXCEPTION_TYPES = ['discrepancy', 'pick_short', 'delivery_failed', 'manual_transport', 'missing_rate', 'billing_hold', 'integration_failed', 'hold'];

    public const EXCEPTION_STATUSES = ['open', 'in_progress', 'resolved'];

    public const SOURCE_MODULES = ['platform', 'masterdata', 'orders', 'warehouse', 'transport', 'billing'];

    public const HOLD_TYPES = ['stock', 'financial', 'address', 'transport', 'client_confirmation'];

    public const DOCUMENT_TYPES = ['pod', 'docket', 'photo', 'waybill', 'invoice', 'packing_list', 'consignment_note', 'label'];

    public const OUTBOX_STATUSES = ['pending', 'published', 'failed', 'dead'];

    public const PAYMENT_TERMS_PATTERN = '/^(prepaid|eom|net_\d{1,3})$/';

    public const INVOICE_MODES = ['per_job', 'monthly'];

    public const LEG_TYPES = ['first_leg', 'last_leg', 'both'];

    public const MASTER_STATUSES = ['active', 'inactive'];

    public const INBOUND_TYPES = ['container', 'loose_truck', 'parcel'];

    public const ASN_STATUSES = ['booked', 'arrived', 'receiving', 'putaway', 'closed'];

    public const CONTAINER_SIZES = ['20', '40'];

    public const UNPACK_MODES = ['pallet', 'loose', 'mixed'];

    public const LOCATION_TYPES = ['receiving', 'storage', 'pickface', 'packing', 'staging', 'quarantine'];

    public const UNIT_TYPES = ['pallet', 'carton'];

    public const PALLET_CLASSES = ['standard', 'oversize_wide', 'oversize_high', 'overweight', 'pickface'];

    public const PALLET_SOURCES = ['client_own', 'warehouse_plain', 'chep', 'loscam'];

    public const CONDITIONS = ['good', 'quarantine', 'damaged'];

    public const TASK_TYPES = ['receiving', 'putaway', 'move', 'pick', 'pack', 'load', 'count', 'return_inspection', 'devanning', 'wrap', 'scanning', 'labour', 'waste', 'vas_other'];

    public const TASK_SOURCE_TYPES = ['order', 'fulfilment', 'asn', 'container', 'stocktake', 'wave'];

    public const TASK_STATUSES = ['pending', 'in_progress', 'done', 'cancelled', 'exception'];

    public const BILLABLE_UOMS = ['container', 'pallet', 'carton', 'scan', 'man_hour', 'cbm', 'label'];

    public const MOVEMENT_TYPES = ['receipt', 'putaway', 'pick', 'transfer', 'adjust', 'release', 'return', 'split', 'merge'];

    public const APPROVAL_TYPES = ['rate_card_change', 'credit_note', 'stock_adjustment', 'financial_release', 'price_override', 'poa_quote'];

    public const APPROVAL_STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    public const WEBHOOK_DELIVERY_STATUSES = ['pending', 'delivered', 'failed', 'dead'];

    public const CHARGE_CATEGORIES = ['warehouse', 'vas', 'transport', 'storage', 'other'];

    public const BILLING_UOMS = ['container_20', 'container_40', 'pallet', 'pallet_week', 'pickface_week', 'carton', 'carton_week', 'cbm_week', 'order', 'label', 'scan', 'cbm', 'man_hour', 'delivery'];

    public const TAX_TREATMENTS = ['gst_10', 'gst_free', 'out_of_scope'];

    public const RATE_CARD_STATUSES = ['draft', 'active', 'superseded'];

    public const PRICING_MODES = ['fixed', 'cost_plus', 'percent'];

    public const CHARGE_STATUSES = ['pending', 'needs_review', 'approved', 'invoiced', 'disputed', 'reversed'];

    public const INVOICE_TYPES = ['service', 'storage', 'supplementary', 'monthly'];

    public const INVOICE_STATUSES = ['draft', 'issued', 'part_paid', 'paid', 'void'];

    public const CREDIT_NOTE_STATUSES = ['draft', 'approved', 'issued', 'cancelled'];

    public const QUOTE_STAGES = ['preliminary', 'final'];

    public const QUOTE_STATUSES = ['draft', 'sent', 'accepted', 'rejected', 'expired'];

    public const TRIGGER_EVENTS = ['task.completed', 'asn.putaway_completed', 'outbound.packed', 'shipment.quote_confirmed', 'delivery.extra_charge', 'snapshot.weekly', 'return.financial_decision', 'manual'];

    public const QUANTITY_SOURCES = ['billable_qty', 'pallets', 'pallets_warehouse_plain', 'labels', 'orders', 'cartons', 'scans', 'hours_business', 'hours_after_hours', 'cbm', 'weeks', 'pickface_slots', 'one'];

    public const STATES = ['VIC', 'NSW', 'QLD', 'SA', 'WA', 'TAS', 'NT', 'ACT'];
}
