<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A22: one row per scheduled client report sent (or skipped / failed), so cron re-runs never mail a period twice (X1 / Reports table, CHANGE_REQUESTS #54). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained();
            $table->string('frequency', 10);          // weekly | monthly
            $table->date('period_start');
            $table->date('period_end');
            $table->string('email')->nullable();      // recipient: clients.billing_email, else contact_email
            $table->string('status', 20);             // sent | skipped | failed
            $table->string('error', 500)->nullable(); // skipped: no_address; failed: the mailer's message
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'frequency', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_deliveries');
    }
};
