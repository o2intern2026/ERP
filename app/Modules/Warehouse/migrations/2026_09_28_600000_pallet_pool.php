<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** CHANGE_REQUESTS #169 托盘管理: an emptied pallet can be cleared from its slot into the free pool (released_at) and reused at receiving. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pallets', function (Blueprint $table) {
            $table->timestamp('released_at')->nullable()->after('received_at');
            $table->unsignedInteger('reuse_count')->default(0)->after('released_at');
        });
    }

    public function down(): void
    {
        Schema::table('pallets', fn (Blueprint $table) => $table->dropColumn(['released_at', 'reuse_count']));
    }
};
