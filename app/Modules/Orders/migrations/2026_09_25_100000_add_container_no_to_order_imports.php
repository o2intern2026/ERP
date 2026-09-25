<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CHANGE_REQUESTS #158: the 柜号 a client submission carries — generated in sequence (CTN-<date>-NNNN) when the client gave none,
        // kept as a column so the sequence can be taken under a row lock (DocumentNumbers) and the list can be searched by it.
        Schema::table('order_imports', function (Blueprint $table) {
            $table->string('container_no', 20)->nullable()->after('source')->index();
        });
    }

    public function down(): void
    {
        Schema::table('order_imports', fn (Blueprint $table) => $table->dropColumn('container_no'));
    }
};
