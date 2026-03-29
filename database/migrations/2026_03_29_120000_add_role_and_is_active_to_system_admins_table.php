<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_admins', function (Blueprint $table) {
            $table->string('role', 100)->default('super_admin')->after('password');
            $table->boolean('is_active')->default(true)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('system_admins', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_active']);
        });
    }
};
