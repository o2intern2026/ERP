<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Charge catalogue (A24): a code describes "what fee this is"; rate cards price codes (ERP_PLAN §6.3).
        Schema::create('charge_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('category', 20)->index();
            $table->string('default_uom', 20);
            $table->string('customer_description');
            $table->string('internal_description')->nullable();
            $table->string('tax_treatment', 20)->default('gst_10');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Trigger rules (A24): event + condition → code; quantity_source names the payload field (contracts/charge-codes.md).
        Schema::create('charge_rules', function (Blueprint $table) {
            $table->id();
            $table->string('trigger_event', 40)->index();
            $table->foreignId('charge_code_id')->constrained();
            $table->json('condition')->nullable();
            $table->string('quantity_source', 30);
            $table->string('rate_match_priority', 30)->default('client_then_standard');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('idempotency_key_template', 120);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Versioned rate cards (A5): never edited in place — a price change creates a new version.
        Schema::create('rate_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete(); // null = the standard card
            $table->string('name');
            $table->string('currency', 3)->default('AUD');
            $table->unsignedInteger('version')->default(1);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->boolean('is_standard')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'status']);
        });

        Schema::create('rate_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charge_code_id')->constrained();
            $table->string('pallet_class', 20)->nullable();
            $table->json('threshold_json')->nullable();
            $table->decimal('weight_band_min', 8, 2)->nullable();
            $table->decimal('weight_band_max', 8, 2)->nullable();
            $table->string('zone', 30)->nullable();
            $table->string('pricing_mode', 20)->default('fixed');
            $table->foreignId('carrier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service_level', 20)->nullable();
            $table->decimal('markup_percent', 5, 2)->nullable();
            $table->bigInteger('rate_cents')->nullable(); // null for POA
            $table->bigInteger('min_charge_cents')->nullable();
            $table->boolean('is_poa')->default(false);
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->index(['rate_card_id', 'charge_code_id']);
        });

        // Add the FK the clients table has been waiting for since M1 (contracts/db-schema.md §2).
        Schema::table('clients', function (Blueprint $table) {
            $table->foreign('standard_rate_card_id')->references('id')->on('rate_cards')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->dropForeign(['standard_rate_card_id']));
        Schema::dropIfExists('rate_items');
        Schema::dropIfExists('rate_cards');
        Schema::dropIfExists('charge_rules');
        Schema::dropIfExists('charge_codes');
    }
};
