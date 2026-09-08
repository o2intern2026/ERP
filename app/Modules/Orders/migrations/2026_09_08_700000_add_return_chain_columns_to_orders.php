<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A11 return chain (contracts/CHANGE_REQUESTS.md #38): a return is an order with order_type = return linked to the
 * original order; Finance's decision is recorded on the return order so return.financial_decision is emitted once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('original_order_id')->nullable()->after('customer_quote_id')->index();
            $table->timestamp('return_inspected_at')->nullable()->after('original_order_id');
            $table->string('return_decision', 20)->nullable()->after('return_inspected_at');
            $table->foreignId('return_decided_by')->nullable()->after('return_decision')->constrained('users')->nullOnDelete();
            $table->timestamp('return_decided_at')->nullable()->after('return_decided_by');
            $table->text('return_decision_note')->nullable()->after('return_decided_at');
        });

        Schema::table('order_lines', function (Blueprint $table) {
            // A return line points at the goods line it gives back (return.requested / return.financial_decision payloads).
            $table->unsignedBigInteger('original_order_line_id')->nullable()->after('stock_unit_ref')->index();
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropColumn('original_order_line_id');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('return_decided_by');
            $table->dropColumn(['original_order_id', 'return_inspected_at', 'return_decision', 'return_decided_at', 'return_decision_note']);
        });
    }
};
