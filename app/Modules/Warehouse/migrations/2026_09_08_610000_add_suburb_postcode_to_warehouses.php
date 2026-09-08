<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Carrier gateways quote from a complete pickup address (suburb + postcode); the plan's model only had address + state.
        Schema::table('warehouses', function (Blueprint $table) {
            $table->string('suburb', 100)->nullable()->after('address');
            $table->string('postcode', 10)->nullable()->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', fn (Blueprint $table) => $table->dropColumn(['suburb', 'postcode']));
    }
};
