<?php

return [

    /*
     * Bind the Fake implementations in app/Support/Fakes (contracts/services.md) instead of the module
     * implementations. Used by tests and by any seat whose dependency block is not merged yet.
     */
    'use_fake_services' => (bool) env('USE_FAKE_SERVICES', false),

    // ERP_PLAN §0.2 rule 7: one currency, integer cents.
    'currency' => 'AUD',

    // Storage is billed per pallet·week; the billing week starts on this day (ERP_PLAN §4.2 stock_snapshots).
    'storage_week_starts_on' => 'monday',

];
