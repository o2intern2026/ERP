<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Transactional outbox (ERP_PLAN §0.2 rule 4, A31). Rows are inserted in the publisher's own DB transaction.
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('event_name', 60)->index();
            $table->unsignedSmallInteger('event_version')->default(1);
            $table->string('correlation_id', 60)->nullable()->index();
            $table->unsignedBigInteger('job_id')->nullable()->index();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('published_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('created_at');
            $table->index(['status', 'available_at']);
        });

        // Inbox: one row per (event, consumer) makes every consumer idempotent.
        Schema::create('consumed_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id');
            $table->string('consumer', 150);
            $table->timestamp('consumed_at');
            $table->unique(['event_id', 'consumer']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumed_events');
        Schema::dropIfExists('outbox_events');
    }
};
