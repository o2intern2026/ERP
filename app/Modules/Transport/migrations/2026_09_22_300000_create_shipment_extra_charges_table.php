<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CHANGE_REQUESTS #135 (audit TMS-11): every 报告配送额外费用 leaves a Transport-side record next to the `delivery.extra_charge`
 * event it published (same transaction), so the shipment page can list what was already reported and refuse a silent repeat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_extra_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->restrictOnDelete();
            $table->string('charge_type', 20); // waiting | redelivery | failed | other — as the event payload
            $table->decimal('qty', 10, 2);
            $table->string('uom', 20); // delivery | man_hour
            $table->unsignedInteger('cost_cents')->nullable(); // the carrier's cost to us, AUD cents; Billing prices the client side
            $table->text('note');
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reported_at');
            $table->uuid('event_id')->nullable()->index(); // outbox_events.event_id of the delivery.extra_charge published with this row
            $table->timestamps();

            $table->index(['shipment_id', 'charge_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_extra_charges');
    }
};
