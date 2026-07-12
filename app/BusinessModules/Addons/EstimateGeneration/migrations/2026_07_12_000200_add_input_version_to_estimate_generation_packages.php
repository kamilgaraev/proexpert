<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_generation_packages', function (Blueprint $table): void {
            $table->char('input_version', 71)->nullable()->after('session_id');
            $table->index(['session_id', 'input_version'], 'eg_packages_session_input_idx');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("UPDATE estimate_generation_packages SET input_version = metadata->>'input_version' WHERE metadata->>'generated_from' = 'estimate_generation_v2' AND metadata->>'input_version' ~ '^sha256:[a-f0-9]{64}$'");
            DB::statement("ALTER TABLE estimate_generation_packages ADD CONSTRAINT eg_packages_input_version_ck CHECK (input_version IS NULL OR input_version ~ '^sha256:[a-f0-9]{64}$')");
        }
    }

    public function down(): void
    {
        Schema::table('estimate_generation_packages', function (Blueprint $table): void {
            $table->dropIndex('eg_packages_session_input_idx');
            $table->dropColumn('input_version');
        });
    }
};
