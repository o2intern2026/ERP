<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CHANGE_REQUESTS #122: the ONE devanning task of a shared physical container spans clients, so job_id / client_id are NULL
 * for source_type = physical_container (every other task keeps both — the FKs stay). physical_container_id names the box
 * explicitly (source_id alone would collide with other source types in joins).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_tasks', function (Blueprint $table): void {
            $table->unsignedBigInteger('job_id')->nullable()->change();
            $table->unsignedBigInteger('client_id')->nullable()->change();
            $table->foreignId('physical_container_id')->nullable()->after('container_id')->constrained('physical_containers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('physical_container_id');
            $table->unsignedBigInteger('job_id')->nullable(false)->change();
            $table->unsignedBigInteger('client_id')->nullable(false)->change();
        });
    }
};
