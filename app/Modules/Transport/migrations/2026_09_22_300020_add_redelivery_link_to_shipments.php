<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CHANGE_REQUESTS #135 (audit TMS-10): a redelivery shipment points at the failed shipment it re-attempts, so the failed page
 * links (and never offers 创建重派运输单 twice) and the new page names its origin. `_redelivery_of` in the copied quote's
 * raw_response stays for older readers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('redelivery_of_shipment_id')->nullable()->after('delivery_run_id')->constrained('shipments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('redelivery_of_shipment_id');
        });
    }
};
