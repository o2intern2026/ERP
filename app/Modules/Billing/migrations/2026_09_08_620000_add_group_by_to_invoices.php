<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tester feedback #4: invoices for any period, grouped by Job (default) or by order.
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('group_by', 10)->default('job')->after('invoice_type');
        });
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->nullable()->after('job_id')->index(); // resolved from the charge's source at draft time (orders is an X1 table, no FK)
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', fn (Blueprint $table) => $table->dropColumn('order_id'));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('group_by'));
    }
};
