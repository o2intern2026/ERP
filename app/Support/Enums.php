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

    public const STATES = ['VIC', 'NSW', 'QLD', 'SA', 'WA', 'TAS', 'NT', 'ACT'];
}
