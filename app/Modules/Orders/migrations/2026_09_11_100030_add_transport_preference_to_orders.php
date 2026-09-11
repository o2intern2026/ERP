<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** CHANGE_REQUESTS #118: the transport option the client chose with the 估价 (source, service level, carrier, customer price, eta, who / when). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->json('transport_preference')->nullable()->after('customer_quote_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('transport_preference');
        });
    }
};
