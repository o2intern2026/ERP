<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CHANGE_REQUESTS #126: 底层库位 — a storage location is `standard` (default) or `bottom` (the bottom rack level, where expensive goods
 * go and storage costs more). rack_level records the physical beam (1 = floor / bottom beam) so a middle tier can be added later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->string('storage_tier', 20)->default('standard')->after('type');
            $table->unsignedTinyInteger('rack_level')->nullable()->after('storage_tier');
            $table->index(['warehouse_id', 'storage_tier']);
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->dropIndex(['warehouse_id', 'storage_tier']);
            $table->dropColumn(['storage_tier', 'rack_level']);
        });
    }
};
