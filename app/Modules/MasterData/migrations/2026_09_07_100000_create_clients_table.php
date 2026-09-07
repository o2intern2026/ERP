<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('abn', 20)->nullable();
            $table->string('leg_type', 20)->default('both');
            $table->string('contact_name')->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('contact_email')->nullable();
            $table->string('billing_email')->nullable();
            $table->string('address')->nullable();
            $table->string('suburb', 100)->nullable();
            $table->string('state', 3)->nullable();
            $table->string('postcode', 10)->nullable();
            $table->string('status', 20)->default('active');
            // Billing behaviour per client (ERP_PLAN §0.2 rule 9, §6.2): payment_terms only sets the due date.
            $table->string('payment_terms', 20)->default('eom');
            $table->string('invoice_mode', 20)->default('per_job');
            $table->decimal('default_markup_percent', 5, 2)->default(0);
            $table->time('dispatch_cutoff_time')->nullable();
            // rate_cards is a Billing table (M6); the FK constraint is added there.
            $table->unsignedBigInteger('standard_rate_card_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
