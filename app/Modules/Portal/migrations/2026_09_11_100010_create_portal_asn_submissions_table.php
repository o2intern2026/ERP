<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CHANGE_REQUESTS #116: one row per client self-service ASN submission (portal form or API push) — who, through which channel,
 * and the Idempotency-Key that lets an API replay return the first ASN instead of creating a second one. The ASN itself is
 * Warehouse's `asns` row (created_by_type = client).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_asn_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('asn_id')->constrained('asns')->cascadeOnDelete();
            $table->string('channel', 10); // portal | api
            $table->string('idempotency_key', 128)->nullable();
            $table->foreignId('token_id')->nullable()->constrained('order_api_tokens')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('line_count')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['client_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_asn_submissions');
    }
};
