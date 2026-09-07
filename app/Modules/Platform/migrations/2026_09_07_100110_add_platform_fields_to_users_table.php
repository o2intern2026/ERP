<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Client-role users belong to exactly one client; staff have null (global client scope, A1).
            $table->foreignId('client_id')->nullable()->after('password')->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('client_id');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn(['is_active', 'last_login_at']);
        });
    }
};
