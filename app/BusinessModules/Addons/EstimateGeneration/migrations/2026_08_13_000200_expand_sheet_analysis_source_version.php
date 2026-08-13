<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'estimate_generation_sheet_analysis_operations';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, static function (Blueprint $table): void {
            $table->char('source_version', 71)->change();
        });
        $this->assertLength(71);
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }
        if (DB::table(self::TABLE)->whereRaw('char_length(source_version) > 64')->exists()) {
            throw new \RuntimeException('estimate_generation.sheet_analysis_source_version_rollback_unsafe');
        }

        Schema::table(self::TABLE, static function (Blueprint $table): void {
            $table->char('source_version', 64)->change();
        });
        $this->assertLength(64);
    }

    private function assertLength(int $expected): void
    {
        $actual = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', self::TABLE)
            ->where('column_name', 'source_version')
            ->value('character_maximum_length');
        if ((int) $actual !== $expected) {
            throw new \RuntimeException('estimate_generation.sheet_analysis_source_version_length_invalid');
        }
    }
};
