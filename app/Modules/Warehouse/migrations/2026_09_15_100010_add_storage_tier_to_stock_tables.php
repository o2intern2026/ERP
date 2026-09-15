<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CHANGE_REQUESTS #126: the declared storage tier travels with the goods — asn_lines (from the order line / manifest / staff),
 * stock_units.required_storage_tier (copied at receiving, the putaway check reads it) and the daily snapshot (both the tier of the
 * location the unit occupies and the unit's required tier, so the weekly bottom-level surcharge reads the last snapshot only).
 * Snapshot rows written before this CR keep NULL in both columns and never produce a surcharge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asn_lines', function (Blueprint $table): void {
            $table->string('storage_tier', 20)->default('standard')->after('cbm');
            $table->string('storage_tier_source', 12)->nullable()->after('storage_tier');
        });

        Schema::table('stock_units', function (Blueprint $table): void {
            $table->string('required_storage_tier', 20)->default('standard')->after('pallet_source');
            $table->string('storage_tier_override_reason', 255)->nullable()->after('required_storage_tier');
        });

        Schema::table('stock_snapshots', function (Blueprint $table): void {
            $table->string('location_storage_tier', 20)->nullable()->after('location_type');
            $table->string('required_storage_tier', 20)->nullable()->after('location_storage_tier');
        });
    }

    public function down(): void
    {
        Schema::table('stock_snapshots', fn (Blueprint $table) => $table->dropColumn(['location_storage_tier', 'required_storage_tier']));
        Schema::table('stock_units', fn (Blueprint $table) => $table->dropColumn(['required_storage_tier', 'storage_tier_override_reason']));
        Schema::table('asn_lines', fn (Blueprint $table) => $table->dropColumn(['storage_tier', 'storage_tier_source']));
    }
};
