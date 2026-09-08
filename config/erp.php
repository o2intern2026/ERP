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

    // Storage is billed per pallet·week; the billing week starts on this day (ERP_PLAN §4.2 stock_snapshots).
    'storage_week_starts_on' => 'monday',

];
