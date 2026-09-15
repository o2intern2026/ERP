<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CHANGE_REQUESTS #126 (lead answer 7): a rate item may apply to one warehouse only (Melbourne / Sydney price standards).
 * NULL = every warehouse. RateService::matchItem prefers a matching warehouse row over the all-warehouse row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_items', function (Blueprint $table): void {
            $table->foreignId('warehouse_id')->nullable()->after('service_level')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rate_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('warehouse_id');
        });
    }
};
