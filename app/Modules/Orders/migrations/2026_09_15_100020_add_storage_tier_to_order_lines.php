<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CHANGE_REQUESTS #126 (C as integrator in the X1 Orders zone): the storage tier declared for a goods line — null when nothing was
 * declared (no 存储等级 column), `standard` | `bottom` otherwise; source `client` (portal sheet), `staff` (staff import / form) or
 * `value_rule` (pre-filled from the declared unit price). Copied onto the ASN line when the ASN is built from the order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->string('storage_tier', 20)->nullable()->after('cbm');
            $table->string('storage_tier_source', 12)->nullable()->after('storage_tier');
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', fn (Blueprint $table) => $table->dropColumn(['storage_tier', 'storage_tier_source']));
    }
};
