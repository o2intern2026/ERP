<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Unified document store (A29): POD, docket, photo, waybill, invoice, packing_list, … Written only through DocumentService.
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30)->index();
            $table->string('related_type', 40);
            $table->unsignedBigInteger('related_id');
            $table->foreignId('job_id')->nullable()->constrained('jobs')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('client_visible')->default(false);
            $table->string('storage_path');
            $table->string('original_name')->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['related_type', 'related_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
