<?php

declare(strict_types=1);

use App\Services\CompletedWork\ActiveJournalWorkVolumeUniqueness;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasColumn('completed_works', 'journal_work_volume_id')) {
            return;
        }

        app(ActiveJournalWorkVolumeUniqueness::class)->ensureUniqueIndex();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS '.ActiveJournalWorkVolumeUniqueness::INDEX_NAME);
        }
    }
};
