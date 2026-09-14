<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CHANGE_REQUESTS #124 (C as integrator in the Transport zone): an inbound collection shipment — Transport collects the goods of a
 * 预报单 at the client's pickup address and delivers them to our warehouse — has no order behind it. `order_id` becomes nullable,
 * `asn_id` names the ASN (Warehouse's table, no FK across modules) and `asn_activity_version` mirrors asns.collection_version so
 * a re-request re-quotes the same shipment once and an out-of-order replay is ignored. `carrier_costs` has no order_id — untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->unsignedBigInteger('order_id')->nullable()->change();
            $table->unsignedBigInteger('asn_id')->nullable()->index()->after('order_id');
            $table->unsignedInteger('asn_activity_version')->nullable()->after('asn_id');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropColumn(['asn_id', 'asn_activity_version']);
            $table->unsignedBigInteger('order_id')->nullable(false)->change();
        });
    }
};
