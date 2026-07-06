<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'landing_admins_is_active_index';

    public function up(): void
    {
        if (!Schema::hasColumn('landing_admins', 'is_active')) {
            Schema::table('landing_admins', function (Blueprint $table): void {
                $table->boolean('is_active')->default(true);
            });
        }

        if (!Schema::hasIndex('landing_admins', self::INDEX_NAME)) {
            Schema::table('landing_admins', function (Blueprint $table): void {
                $table->index('is_active', self::INDEX_NAME);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('landing_admins', self::INDEX_NAME)) {
            Schema::table('landing_admins', function (Blueprint $table): void {
                $table->dropIndex(self::INDEX_NAME);
            });
        }

        if (Schema::hasColumn('landing_admins', 'is_active')) {
            Schema::table('landing_admins', function (Blueprint $table): void {
                $table->dropColumn('is_active');
            });
        }
    }
};
