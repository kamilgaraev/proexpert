<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new \RuntimeException('estimate_generation_settings_snapshot_hash_requires_postgresql');
        }

        DB::statement("SET lock_timeout TO '2s'");
        DB::statement('ALTER TABLE estimate_generation_setting_snapshots ADD COLUMN IF NOT EXISTS snapshot_hash char(64) NULL');
        DB::statement(<<<'SQL'
DO $$ BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = current_schema() AND table_name = 'estimate_generation_setting_snapshots'
      AND column_name = 'snapshot_hash' AND data_type = 'character' AND character_maximum_length = 64
  ) THEN
    RAISE EXCEPTION 'estimate_generation_setting_snapshots.snapshot_hash definition mismatch';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'eg_setting_snapshot_hash_ck') THEN
    ALTER TABLE estimate_generation_setting_snapshots ADD CONSTRAINT eg_setting_snapshot_hash_ck CHECK (snapshot_hash IS NULL OR (snapshot_hash ~ '^[a-f0-9]{64}$' AND length(snapshot_hash) = 64)) NOT VALID;
  END IF;
END $$
SQL);
        DB::statement('RESET lock_timeout');
    }

    public function down(): void
    {
    }
};
