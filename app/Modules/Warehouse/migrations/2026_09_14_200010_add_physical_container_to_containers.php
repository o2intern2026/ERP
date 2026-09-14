<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CHANGE_REQUESTS #122: a container row (one ASN, one client, one Job) may point at the physical box it shares with other rows.
 * NULL for every existing row — the FCL / single-client path is untouched. The share columns are the snapshot of the last
 * allocation (auditable, re-emittable with activity_version + 1 through 重算分摊).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('containers', function (Blueprint $table): void {
            $table->foreignId('physical_container_id')->nullable()->after('job_id')->constrained('physical_containers')->nullOnDelete();
            $table->string('devanning_basis', 20)->nullable()->after('line_count');
            $table->decimal('devanning_basis_qty', 10, 3)->nullable()->after('devanning_basis');
            $table->decimal('devanning_share', 6, 4)->nullable()->after('devanning_basis_qty');
            $table->boolean('devanning_basis_provisional')->default(false)->after('devanning_share');
        });
    }

    public function down(): void
    {
        Schema::table('containers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('physical_container_id');
            $table->dropColumn(['devanning_basis', 'devanning_basis_qty', 'devanning_share', 'devanning_basis_provisional']);
        });
    }
};
