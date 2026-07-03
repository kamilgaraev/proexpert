<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const LEGACY_COLUMN = 'pro' . 'helper_values';

    public function up(): void
    {
        if (
            Schema::hasTable('one_c_exchange_conflicts')
            && Schema::hasColumn('one_c_exchange_conflicts', self::LEGACY_COLUMN)
            && !Schema::hasColumn('one_c_exchange_conflicts', 'most_values')
        ) {
            Schema::table('one_c_exchange_conflicts', static function (Blueprint $table): void {
                $table->renameColumn(self::LEGACY_COLUMN, 'most_values');
            });
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('one_c_exchange_conflicts')
            && Schema::hasColumn('one_c_exchange_conflicts', 'most_values')
            && !Schema::hasColumn('one_c_exchange_conflicts', self::LEGACY_COLUMN)
        ) {
            Schema::table('one_c_exchange_conflicts', static function (Blueprint $table): void {
                $table->renameColumn('most_values', self::LEGACY_COLUMN);
            });
        }
    }
};
