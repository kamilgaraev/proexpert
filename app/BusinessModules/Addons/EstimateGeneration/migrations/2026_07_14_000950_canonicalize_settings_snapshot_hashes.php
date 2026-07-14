<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Settings\SettingsSnapshotHash;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('estimate_generation_setting_snapshots')
            || ! Schema::hasColumn('estimate_generation_setting_snapshots', 'snapshot_hash')) {
            return;
        }
        DB::statement('DROP TRIGGER IF EXISTS eg_setting_snapshot_immutable ON estimate_generation_setting_snapshots');
        try {
            DB::table('estimate_generation_setting_snapshots')->orderBy('id')->chunkById(200, static function ($rows): void {
                foreach ($rows as $row) {
                    $snapshot = is_string($row->snapshot) ? json_decode($row->snapshot, true, 64, JSON_THROW_ON_ERROR) : $row->snapshot;
                    if (! is_array($snapshot)) {
                        throw new RuntimeException('estimate_generation_settings_snapshot_invalid');
                    }
                    DB::table('estimate_generation_setting_snapshots')->where('id', $row->id)->update([
                        'snapshot_hash' => SettingsSnapshotHash::calculate($snapshot),
                    ]);
                }
            });
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS eg_setting_snapshot_immutable ON estimate_generation_setting_snapshots');
            DB::statement('CREATE TRIGGER eg_setting_snapshot_immutable BEFORE UPDATE OR DELETE ON estimate_generation_setting_snapshots FOR EACH ROW EXECUTE FUNCTION eg_setting_immutable()');
        }
    }

    public function down(): void {}
};
