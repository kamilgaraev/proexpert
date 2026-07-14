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
        if (! Schema::hasTable('estimate_generation_setting_snapshots')) {
            return;
        }
        if (! Schema::hasColumn('estimate_generation_setting_snapshots', 'snapshot_hash')) {
            Schema::table('estimate_generation_setting_snapshots', function (Blueprint $table): void {
                $table->char('snapshot_hash', 64)->nullable()->after('snapshot');
            });
        }
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('estimate_generation_settings_snapshot_hash_requires_postgresql');
        }

        DB::statement('LOCK TABLE estimate_generation_setting_snapshots IN SHARE ROW EXCLUSIVE MODE');
        DB::statement('DROP TRIGGER IF EXISTS eg_setting_snapshot_immutable ON estimate_generation_setting_snapshots');
        try {
            DB::statement(<<<'SQL'
DO $$ BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = current_schema() AND table_name = 'estimate_generation_setting_snapshots'
      AND column_name = 'snapshot_hash' AND data_type = 'character' AND character_maximum_length = 64
  ) THEN
    RAISE EXCEPTION 'estimate_generation_setting_snapshots.snapshot_hash definition mismatch';
  END IF;
END $$
SQL);
            DB::statement('ALTER TABLE estimate_generation_setting_snapshots DROP CONSTRAINT IF EXISTS eg_setting_snapshot_hash_ck');
            $lastId = 0;
            while (true) {
                $rows = DB::select('SELECT id FROM estimate_generation_setting_snapshots WHERE id > ? ORDER BY id LIMIT 500', [$lastId]);
                if ($rows === []) {
                    break;
                }
                $ids = array_map(static fn (object $row): int => (int) $row->id, $rows);
                $lastId = max($ids);
                DB::table('estimate_generation_setting_snapshots')
                    ->whereIn('id', $ids)
                    ->update(['snapshot_hash' => DB::raw("encode(pg_catalog.sha256(pg_catalog.convert_to(snapshot::text, 'UTF8')), 'hex')")]);
            }

            DB::statement("ALTER TABLE estimate_generation_setting_snapshots ADD CONSTRAINT eg_setting_snapshot_hash_ck CHECK (snapshot_hash ~ '^[a-f0-9]{64}$' AND length(snapshot_hash) = 64) NOT VALID");
            DB::statement('ALTER TABLE estimate_generation_setting_snapshots VALIDATE CONSTRAINT eg_setting_snapshot_hash_ck');
            DB::statement('ALTER TABLE estimate_generation_setting_snapshots ALTER COLUMN snapshot_hash SET NOT NULL');
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS eg_setting_snapshot_immutable ON estimate_generation_setting_snapshots');
            DB::statement('CREATE TRIGGER eg_setting_snapshot_immutable BEFORE UPDATE OR DELETE ON estimate_generation_setting_snapshots FOR EACH ROW EXECUTE FUNCTION eg_setting_immutable()');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('estimate_generation_setting_snapshots')
            || ! Schema::hasColumn('estimate_generation_setting_snapshots', 'snapshot_hash')) {
            return;
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE estimate_generation_setting_snapshots DROP CONSTRAINT IF EXISTS eg_setting_snapshot_hash_ck');
            DB::statement('ALTER TABLE estimate_generation_setting_snapshots DROP COLUMN IF EXISTS snapshot_hash');

            return;
        }
        Schema::table('estimate_generation_setting_snapshots', function (Blueprint $table): void {
            $table->dropColumn('snapshot_hash');
        });
    }
};
