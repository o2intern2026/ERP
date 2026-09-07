<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A19 approval centre (PLT-7): sensitive actions take effect only after a second person approves.
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30)->index();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_id')->nullable()->constrained('jobs')->nullOnDelete();
            $table->foreignId('requested_by')->constrained('users');
            $table->text('request_note')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
        });

        // A23 outbound webhooks: every outbox event can be pushed to registered endpoints (signed, retried by the outbox).
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('url', 500);
            $table->string('secret', 80);
            $table->json('events'); // list of event names, or ["*"]
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
            $table->uuid('event_id');
            $table->string('event_name', 60);
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('created_at');
            $table->unique(['endpoint_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('approvals');
    }
};
