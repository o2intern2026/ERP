<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CHANGE_REQUESTS #145 自动导入: per-client defaults for lists that arrive without a person on the page (API push, inbox folder) —
        // group_by, address_type_default, auto_confirm, inbox_enabled, notify_email. NULL = today's rules, nothing automated.
        Schema::table('clients', function (Blueprint $table) {
            $table->json('import_defaults')->nullable()->after('dispatch_cutoff_time');
        });
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('import_defaults'));
    }
};
