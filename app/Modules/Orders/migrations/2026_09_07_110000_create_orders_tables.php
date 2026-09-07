<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 24)->unique();
            $table->foreignId('client_id')->constrained();
            $table->foreignId('job_id')->constrained('jobs');
            $table->string('order_type', 30);
            $table->string('source', 20);
            $table->string('external_ref')->nullable();
            $table->string('consignment_mark')->nullable();
            $table->string('fba_reference')->nullable();
            $table->json('pickup_address')->nullable();
            $table->string('deliver_to_name');
            $table->string('deliver_to_phone', 40)->nullable();
            $table->string('deliver_to_address');
            $table->string('deliver_to_suburb', 100);
            $table->string('deliver_to_state', 3);
            $table->string('deliver_to_postcode', 10);
            $table->string('deliver_to_address_type', 20)->default('business');
            $table->date('requested_date');
            $table->string('operational_status', 20)->default('received');
            $table->string('fulfilment_status', 20)->default('unfulfilled');
            $table->string('billing_status', 30)->default('unbilled');
            $table->string('service_level', 20)->default('standard');
            $table->boolean('tailgate_required')->default(false);
            $table->string('tailgate_reason', 30)->nullable();
            // Billing owns customer_quotes and adds the FK at M6.
            $table->unsignedBigInteger('customer_quote_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['client_id', 'external_ref']);
            $table->index(['client_id', 'operational_status']);
            $table->index(['requested_date', 'deliver_to_state']);
            $table->index('consignment_mark');
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('description_cn')->nullable();
            $table->string('description_en')->nullable();
            $table->string('hs_code', 30)->nullable();
            $table->string('material')->nullable();
            $table->string('usage')->nullable();
            $table->string('brand')->nullable();
            $table->string('package_type', 30);
            $table->unsignedInteger('carton_qty');
            $table->unsignedInteger('unit_qty')->nullable();
            $table->unsignedBigInteger('unit_price_cents')->nullable();
            $table->unsignedBigInteger('total_price_cents')->nullable();
            $table->decimal('actual_weight_kg', 10, 3)->nullable();
            $table->unsignedInteger('length_mm')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();
            $table->decimal('cbm', 10, 4)->nullable();
            $table->unsignedInteger('qty_shipped')->default(0);
            $table->unsignedInteger('qty_backordered')->default(0);
            // Warehouse tables arrive at M2; these remain unconstrained cross-module references.
            $table->unsignedBigInteger('asn_line_id')->nullable()->index();
            $table->string('stock_unit_ref', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('declared_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('package_type', 30);
            $table->unsignedInteger('qty');
            $table->decimal('weight_kg', 10, 3)->nullable();
            $table->unsignedInteger('length_mm')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();
            $table->timestamps();
        });

        Schema::create('fulfilments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('seq', 20);
            // Warehouse and Transport own these target tables and add no cross-module writes here.
            $table->unsignedBigInteger('warehouse_id');
            $table->string('status', 20)->default('allocated');
            $table->unsignedBigInteger('shipment_id')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'seq']);
            $table->index('shipment_id');
        });

        Schema::create('fulfilment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fulfilment_id')->constrained('fulfilments')->cascadeOnDelete();
            $table->foreignId('order_line_id')->constrained('order_lines')->cascadeOnDelete();
            $table->unsignedInteger('qty');
            $table->unique(['fulfilment_id', 'order_line_id']);
        });

        Schema::create('client_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('contact_name')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address');
            $table->string('suburb', 100);
            $table->string('state', 3);
            $table->string('postcode', 10);
            $table->string('address_type', 20)->default('business');
            $table->text('default_instructions')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'usage_count']);
        });

        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            // Additive Orders-local field: keeps operational, fulfilment and billing histories distinct.
            $table->string('dimension', 20)->default('operational');
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('actor_type', 20);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at');

            $table->index(['order_id', 'created_at']);
        });

        Schema::create('order_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained();
            $table->string('source', 20);
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('errors')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_imports');
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('client_addresses');
        Schema::dropIfExists('fulfilment_lines');
        Schema::dropIfExists('fulfilments');
        Schema::dropIfExists('declared_packages');
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
    }
};
