<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A4b order API stub (contracts/CHANGE_REQUESTS.md #39): bearer tokens per client (hash only) and the Idempotency-Key
 * ledger that maps a client's key to the order it created, so a replayed POST returns the same order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('order_api_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('idempotency_key', 128);
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['client_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_api_idempotency_keys');
        Schema::dropIfExists('order_api_tokens');
    }
};
