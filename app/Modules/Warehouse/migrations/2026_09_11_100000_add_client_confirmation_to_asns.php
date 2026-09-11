<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** CHANGE_REQUESTS #116: a client-submitted ASN (created_by_type = client — portal form or API push) waits for customer service to confirm it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asns', function (Blueprint $table): void {
            $table->timestamp('client_confirmed_at')->nullable()->after('unplanned_confirmed');
            $table->foreignId('client_confirmed_by')->nullable()->after('client_confirmed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('asns', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('client_confirmed_by');
            $table->dropColumn('client_confirmed_at');
        });
    }
};
