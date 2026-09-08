<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A21 reports aggregate orders by intake date and find the `delivered` transitions in a period: index the columns they range over (additive, X1 tables). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index('created_at', 'orders_created_at_index');
            $table->index(['client_id', 'created_at'], 'orders_client_created_index');
        });
        Schema::table('order_events', function (Blueprint $table) {
            $table->index(['dimension', 'to_status', 'created_at'], 'order_events_status_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('order_events', function (Blueprint $table) {
            $table->dropIndex('order_events_status_created_index');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_client_created_index');
            $table->dropIndex('orders_created_at_index');
        });
    }
};
