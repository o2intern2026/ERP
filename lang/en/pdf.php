<?php

/*
 * Every word printed on a client-facing PDF — 入库单 / goods receipt, tax invoice, unit and location labels, consignment note,
 * own-fleet shipping label, proof of delivery (CHANGE_REQUESTS #114, lead request 2026-09-11). Documents leave the company, so they
 * are English; the screens stay Chinese (lang/zh). This is the only lang/en file: the app locale is zh and `pdf.*` resolves here
 * through the fallback locale, so PDF templates need no locale switch and must not use any other namespace (PdfEnglishTest).
 */
return [

    'receipt' => [
        'title' => 'Goods Receipt',
        'draft' => 'DRAFT',
        'unplanned_badge' => 'Unplanned arrival',
        'receipt_no' => 'Receipt no.',
        'asn_no' => 'ASN no.',
        'batch' => 'Batch',
        'client' => 'Client',
        'warehouse' => 'Warehouse',
        'job' => 'Job no.',
        'inbound_type' => 'Inbound type',
        'arrived_at' => 'Arrived / received',
        'completed_at' => 'Completed',
        'delivery_reference' => 'Delivery reference',
        'receiver' => 'Received by',
        'printed_at' => 'Printed',
        'mark' => 'Mark',
        'description' => 'Description',
        'container' => 'Container',
        'expected' => 'Expected',
        'received' => 'Received',
        'damaged' => 'Damaged',
        'variance' => 'Variance',
        'variance_reason' => 'Variance reason',
        'pallets' => 'Pallets',
        'unit_labels' => 'Unit labels',
        'totals' => 'Totals',
        'lines_suffix' => 'lines',
        'units_suffix' => 'stock units',
        'batches_suffix' => 'batches',
        'rollup_title' => 'ASN roll-up',
        'lines_received' => ':done of :total lines received',
        'sign_warehouse' => 'Warehouse — received by',
        'sign_driver' => 'Delivery driver',
        'sign_client' => 'Client representative',
        'date' => 'Date',
        'footer' => 'Variance = received + damaged − expected. Damaged cartons are held in quarantine and still count as received. Unit label codes can be looked up on the stock enquiry and scan pages.',
    ],

    'inbound_types' => [
        'container' => 'Container',
        'loose_truck' => 'Loose truck',
        'parcel' => 'Parcel',
    ],

    'unit_types' => [
        'pallet' => 'Pallet',
        'carton' => 'Carton',
    ],

    'units' => [
        'carton' => 'ctn',
    ],

    'location_types' => [
        'receiving' => 'Receiving',
        'storage' => 'Storage',
        'pickface' => 'Pickface',
        'packing' => 'Packing',
        'staging' => 'Staging',
        'quarantine' => 'Quarantine',
    ],

    'invoice' => [
        'title' => 'TAX INVOICE',
        'bill_to' => 'Bill to',
        'invoice_date' => 'Invoice date',
        'due' => 'Due',
        'type' => 'Type',
        'code' => 'Code',
        'description' => 'Description',
        'qty' => 'Qty',
        'uom' => 'UOM',
        'amount_ex_gst' => 'Amount (ex GST)',
        'gst' => 'GST',
        'job' => 'Job',
        'order' => 'Order',
        'subtotal' => 'Subtotal (ex GST)',
        'total' => 'Total AUD',
        'footer' => 'Every line refers to a charge (#) that links back to its source document in the ERP. Amounts in AUD; GST at 10% where applicable.',
    ],

    'invoice_types' => [
        'service' => 'Service',
        'storage' => 'Storage',
        'supplementary' => 'Supplementary',
        'monthly' => 'Monthly',
    ],

    'payment_terms' => [
        'prepaid' => 'Prepaid',
        'eom' => 'End of month',
        'net' => 'Net :days days',
    ],

    'uoms' => [
        'container_20' => "20' container",
        'container_40' => "40' container",
        'pallet' => 'pallet',
        'pallet_week' => 'pallet / week',
        'pickface_week' => 'pickface / week',
        'carton' => 'carton',
        'carton_week' => 'carton / week',
        'cbm_week' => 'CBM / week',
        'order' => 'order',
        'label' => 'label',
        'scan' => 'scan',
        'cbm' => 'CBM',
        'man_hour' => 'hour',
        'delivery' => 'delivery',
    ],

    'consignment_note' => [
        'title' => 'Consignment Note',
        'job' => 'Job',
        'client' => 'Client',
        'order' => 'Order',
        'carrier' => 'Carrier',
        'service_level' => 'Service level',
        'tailgate' => 'Tailgate required',
        'yes' => 'Yes',
        'no' => 'No',
        'not_selected' => 'Not selected',
        'contents' => 'Contents',
        'no_packages' => 'Packages have not been supplied by the warehouse yet.',
        'label' => 'Label',
        'package_type' => 'Package type',
        'weight' => 'Weight (kg)',
        'dimensions' => 'Dimensions (mm)',
        'total_packages' => 'Total packages',
        'total_weight' => 'Total weight',
        'generated_at' => 'Generated :time',
    ],

    'service_levels' => [
        'standard' => 'Standard',
        'express' => 'Express',
        'same_day' => 'Same day',
    ],

    'package_types' => [
        'carton' => 'Carton',
        'pallet' => 'Pallet',
        'satchel' => 'Satchel',
        'crate' => 'Crate',
    ],

    'label' => [
        'own_fleet' => 'OWN FLEET',
        'ship_to' => 'SHIP TO',
        'shipment' => 'SHIPMENT',
        'package' => 'PACKAGE',
        'weight' => 'WEIGHT',
        'dimensions' => 'DIMENSIONS',
    ],

    'pod' => [
        'title' => 'Proof of Delivery',
        'shipment' => 'Shipment',
        'stop' => 'Stop',
        'recipient_name' => 'Received by',
        'delivered_at' => 'Delivered at',
        'signature' => 'Signature',
        'photos' => 'Delivery photos',
    ],

];
