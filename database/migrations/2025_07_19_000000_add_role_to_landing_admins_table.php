<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landing_admins', function (Blueprint $table) {
            if (!Schema::hasColumn('landing_admins', 'role')) {
                $table->string('role')->default('admin');
            }
            if (!Schema::hasColumn('landing_admins', 'is_super')) {
                $table->boolean('is_super')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('landing_admins', function (Blueprint $table) {
            if (Schema::hasColumn('landing_admins', 'role')) {
                $table->dropColumn('role');
            }
            if (Schema::hasColumn('landing_admins', 'is_super')) {
                $table->dropColumn('is_super');
            }
        });
    }
}; 