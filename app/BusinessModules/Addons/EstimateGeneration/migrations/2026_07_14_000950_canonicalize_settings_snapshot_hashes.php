<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Settings\SettingsSnapshotHash;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_generation_setting_snapshot_hashes', function (Blueprint $table): void {
            $table->unsignedBigInteger('setting_snapshot_id');
            $table->string('algorithm', 32);
            $table->char('snapshot_hash', 64);
            $table->timestampTz('created_at');
            $table->primary(['setting_snapshot_id', 'algorithm'], 'eg_setting_snapshot_hash_pk');
            $table->foreign('setting_snapshot_id')->references('id')->on('estimate_generation_setting_snapshots')->restrictOnDelete();
        });

        DB::table('estimate_generation_setting_snapshots')->orderBy('id')->chunkById(200, static function ($rows): void {
            $records = [];
            foreach ($rows as $row) {
                $snapshot = is_string($row->snapshot) ? json_decode($row->snapshot, true, 64, JSON_THROW_ON_ERROR) : $row->snapshot;
                if (! is_array($snapshot)) {
                    throw new RuntimeException('estimate_generation_settings_snapshot_invalid');
                }
                $records[] = [
                    'setting_snapshot_id' => (int) $row->id,
                    'algorithm' => 'jcs-sha256-v1',
                    'snapshot_hash' => SettingsSnapshotHash::calculate($snapshot),
                    'created_at' => now(),
                ];
            }
            DB::table('estimate_generation_setting_snapshot_hashes')->insertOrIgnore($records);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE estimate_generation_setting_snapshot_hashes ADD CONSTRAINT eg_setting_snapshot_hash_value_ck CHECK (algorithm = 'jcs-sha256-v1' AND snapshot_hash ~ '^[a-f0-9]{64}$')");
            DB::unprepared(<<<'SQL'
CREATE TRIGGER eg_setting_snapshot_hash_immutable
BEFORE UPDATE OR DELETE ON estimate_generation_setting_snapshot_hashes
FOR EACH ROW EXECUTE FUNCTION eg_setting_immutable();
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_generation_setting_snapshot_hashes');
    }
};
