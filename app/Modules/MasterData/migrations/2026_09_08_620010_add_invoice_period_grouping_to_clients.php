<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tester feedback #4: each client says how often it wants invoices and whether lines are grouped by Job or by order.
        Schema::table('clients', function (Blueprint $table) {
            $table->string('invoice_period', 12)->default('monthly')->after('invoice_mode');
            $table->string('invoice_grouping', 10)->default('job')->after('invoice_period');
        });
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn(['invoice_period', 'invoice_grouping']));
    }
};
