<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** CHANGE_REQUESTS #135 (audit TMS-09): the driver's free-text note on a failed attempt (required for 其他); photos reuse photo_document_ids. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pods', function (Blueprint $table) {
            $table->text('failure_note')->nullable()->after('failure_reason');
        });
    }

    public function down(): void
    {
        Schema::table('pods', function (Blueprint $table) {
            $table->dropColumn('failure_note');
        });
    }
};
