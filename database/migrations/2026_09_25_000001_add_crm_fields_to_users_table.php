<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('agent')->after('email');
            $table->boolean('is_active')->default(true)->after('role');
            $table->boolean('is_available')->default(true)->after('is_active');
            $table->timestamp('last_assigned_at')->nullable()->after('is_available');
            $table->timestamp('last_seen_at')->nullable()->after('last_assigned_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_active', 'is_available', 'last_assigned_at', 'last_seen_at']);
        });
    }
};
