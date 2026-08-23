<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OPEN_SHIFT_INDEX = 'machinery_shift_reports_one_open_per_asset';

    public function up(): void
    {
        Schema::table('machinery_shift_reports', function (Blueprint $table): void {
            $table->foreignId('finished_by_user_id')->nullable()->after('reported_by_user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable()->after('report_date');
            $table->timestamp('finished_at')->nullable()->after('started_at');
            $table->jsonb('finish_evidence')->nullable()->after('cost_evidence');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                "CREATE UNIQUE INDEX %s ON machinery_shift_reports (asset_id) WHERE status IN ('draft', 'completed') AND deleted_at IS NULL",
                self::OPEN_SHIFT_INDEX,
            ));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS '.self::OPEN_SHIFT_INDEX);
        }

        Schema::table('machinery_shift_reports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('finished_by_user_id');
            $table->dropColumn(['started_at', 'finished_at', 'finish_evidence']);
        });
    }
};
