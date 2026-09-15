<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CHANGE_REQUESTS #125: a collection the client requested in the portal (入库清单 → 需要我们上门提货) carries the plan it ticked — the
 * customer-facing snapshot only, never cost or markup — so Transport confirms the final quote for that option within the client's
 * tolerance; who asked (client | staff) and the portal import it came from are kept for the ASN page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asns', function (Blueprint $table): void {
            $table->json('collection_preference')->nullable()->after('collection_plan'); // {carrier_id, carrier_name, source, service_level, customer_price_cents, eta_days, is_recommended, is_cheapest, is_fastest, chosen_at, chosen_by}
            $table->string('collection_requested_via', 10)->nullable()->after('collection_preference'); // client | staff
            $table->unsignedBigInteger('collection_import_id')->nullable()->index()->after('collection_requested_via'); // Orders' order_imports.id — no FK across modules
        });
    }

    public function down(): void
    {
        Schema::table('asns', function (Blueprint $table): void {
            $table->dropIndex(['collection_import_id']);
            $table->dropColumn(['collection_preference', 'collection_requested_via', 'collection_import_id']);
        });
    }
};
