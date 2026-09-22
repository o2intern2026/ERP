<?php

return [

    /*
     * Bind the Fake implementations in app/Support/Fakes (contracts/services.md) instead of the module
     * implementations. Used by tests and by any seat whose dependency block is not merged yet.
     */
    'use_fake_services' => (bool) env('USE_FAKE_SERVICES', false),

    // ERP_PLAN §0.2 rule 7: one currency, integer cents.
    'currency' => 'AUD',

    // Tester feedback #8: clients can self-register at /register (status `pending` until staff approve). Set ALLOW_SIGNUP=false to hide it.
    'allow_signup' => (bool) env('ALLOW_SIGNUP', true),

    // A TrueType font with CJK glyphs for dompdf documents that carry Chinese labels (入库单 PDF). dompdf's bundled DejaVu Sans has none;
    // drop e.g. Arial Unicode / Noto Sans CJK at storage/fonts/cjk.ttf (must live inside the project — dompdf's chroot) or point PDF_CJK_FONT at it.
    // storage/fonts/ is git-ignored, so this is per checkout / deployment, not per machine. GoodsReceiptService::complete() refuses (zh message)
    // while the file is missing — the stored 入库单 PDF is a client-visible document and would otherwise print every Chinese label blank.
    'pdf_cjk_font' => env('PDF_CJK_FONT', storage_path('fonts/cjk.ttf')),

    // Events a person is waiting for at the screen are delivered right after the business transaction commits, in the same
    // request, up to N hops for that Job (order.confirmed → stock.reserved → fulfilment). Cron's outbox:dispatch stays the safety net.
    'outbox_dispatch_now' => ['order.confirmed'],
    'outbox_dispatch_now_hops' => 3,
    'outbox_dispatch_now_seconds' => 2.0,

    // Storage is billed per pallet·week; the billing week starts on this day (ERP_PLAN §4.2 stock_snapshots).
    'storage_week_starts_on' => 'monday',

    // The seller printed on every tax invoice (audit 2026-09-22 FIN-01 / GAP-04, CHANGE_REQUESTS #133): an Australian tax invoice must
    // name the supplier and its ABN, and the client needs somewhere to pay. The defaults are PLACEHOLDERS so the trial server needs no
    // configuration — replace them through COMPANY_* in .env before go-live. InvoiceService::issue() refuses (zh message) while the ABN
    // is blank, the same way GoodsReceiptService refuses without the CJK font: the stored PDF is the client's permanent copy.
    'company' => [
        'name' => env('COMPANY_NAME', 'Demo Logistics Pty Ltd'),
        'abn' => env('COMPANY_ABN', '12 345 678 901'),
        'address' => env('COMPANY_ADDRESS', '1 Warehouse Road, Moorebank NSW 2170'),
        'phone' => env('COMPANY_PHONE', '+61 2 0000 0000'),
        'email' => env('COMPANY_EMAIL', 'accounts@example.com'),
        'bank_name' => env('COMPANY_BANK_NAME', 'Demo Bank'),
        'bsb' => env('COMPANY_BSB', '000-000'),
        'account_no' => env('COMPANY_ACCOUNT_NO', '12345678'),
        'account_name' => env('COMPANY_ACCOUNT_NAME', 'Demo Logistics Pty Ltd'),
    ],

];
