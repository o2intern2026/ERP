<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CHANGE_REQUESTS #124: 到仓方式 on the 预报单 — the client brings the goods (client_delivers, today's behaviour) or Transport
 * collects them at a pickup address (we_collect). The collection request lives on the ASN; Transport's shipment id, chosen
 * plan and progress are copied back by the Warehouse consumers of Transport's events for display only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asns', function (Blueprint $table): void {
            $table->string('inbound_transport', 20)->default('client_delivers')->after('inbound_type');
            $table->json('collection_address')->nullable()->after('notes');
            $table->date('collection_ready_date')->nullable()->after('collection_address');
            $table->json('collection_packages')->nullable()->after('collection_ready_date');
            $table->text('collection_notes')->nullable()->after('collection_packages');
            $table->timestamp('collection_requested_at')->nullable()->after('collection_notes');
            $table->foreignId('collection_requested_by')->nullable()->after('collection_requested_at')->constrained('users')->nullOnDelete();
            $table->unsignedInteger('collection_version')->default(0)->after('collection_requested_by');
            $table->unsignedBigInteger('collection_shipment_id')->nullable()->index()->after('collection_version'); // Transport's shipments.id — no FK across modules
            $table->string('collection_status', 20)->nullable()->after('collection_shipment_id');
            $table->json('collection_plan')->nullable()->after('collection_status'); // the confirmed plan / client price as Transport announced it
        });
    }

    public function down(): void
    {
        Schema::table('asns', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('collection_requested_by');
            $table->dropColumn([
                'inbound_transport', 'collection_address', 'collection_ready_date', 'collection_packages', 'collection_notes',
                'collection_requested_at', 'collection_version', 'collection_shipment_id', 'collection_status', 'collection_plan',
            ]);
        });
    }
};
