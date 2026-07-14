<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("SET lock_timeout TO '2s'");
        DB::statement('ALTER TABLE estimate_generation_training_datasets ADD COLUMN IF NOT EXISTS control_version integer NOT NULL DEFAULT 0');
        DB::statement("ALTER TABLE estimate_generation_training_datasets ADD COLUMN IF NOT EXISTS trusted_review_status varchar(16) NOT NULL DEFAULT 'draft'");
        DB::statement('ALTER TABLE estimate_generation_training_datasets ADD COLUMN IF NOT EXISTS trusted_review_submitted_by bigint');
        DB::statement('ALTER TABLE estimate_generation_training_datasets ADD COLUMN IF NOT EXISTS trusted_review_submitted_at timestamptz');
        DB::statement('ALTER TABLE estimate_generation_training_datasets ADD COLUMN IF NOT EXISTS trusted_reviewed_by bigint');
        DB::statement('ALTER TABLE estimate_generation_training_datasets ADD COLUMN IF NOT EXISTS trusted_reviewed_at timestamptz');
        DB::statement('ALTER TABLE estimate_generation_training_datasets ADD COLUMN IF NOT EXISTS trusted_review_migrated_from_approval boolean NOT NULL DEFAULT false');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_guard_training_dataset_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' AND OLD.status IN ('approved','archived') THEN RAISE EXCEPTION 'immutable training dataset'; END IF;
  IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
  IF OLD.status = 'approved' AND NEW.status = 'approved'
     AND OLD.dataset_type = 'development'
     AND OLD.trusted_review_status = 'draft'
     AND NEW.trusted_review_status = 'approved'
     AND NEW.trusted_review_migrated_from_approval
     AND NEW.trusted_reviewed_by = OLD.approved_by
     AND NEW.trusted_reviewed_at = OLD.approved_at
     AND (to_jsonb(NEW) - 'trusted_review_status' - 'trusted_reviewed_by' - 'trusted_reviewed_at' - 'trusted_review_migrated_from_approval' - 'updated_at')
         IS NOT DISTINCT FROM
         (to_jsonb(OLD) - 'trusted_review_status' - 'trusted_reviewed_by' - 'trusted_reviewed_at' - 'trusted_review_migrated_from_approval' - 'updated_at') THEN
    RETURN NEW;
  END IF;
  IF OLD.status = 'approved' AND NEW.status = 'archived' THEN
    IF (to_jsonb(NEW) - 'status' - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'status' - 'updated_at') THEN RAISE EXCEPTION 'archive transition may only change status'; END IF;
    RETURN NEW;
  END IF;
  IF OLD.status IN ('approved','archived') AND NEW IS DISTINCT FROM OLD THEN RAISE EXCEPTION 'immutable training dataset'; END IF;
  IF NEW.version <> OLD.version OR NEW.dataset_key <> OLD.dataset_key OR NEW.organization_id <> OLD.organization_id OR NEW.dataset_type <> OLD.dataset_type THEN RAISE EXCEPTION 'immutable dataset identity'; END IF;
  RETURN NEW;
END $$;
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_training_trusted_review_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.dataset_type = 'development' AND NEW.status = 'approved' AND NEW.trusted_review_status <> 'approved' THEN
    RAISE EXCEPTION 'development dataset requires trusted review before approval';
  END IF;
  IF TG_OP = 'UPDATE' AND NEW.trusted_review_status IS DISTINCT FROM OLD.trusted_review_status THEN
    IF NOT (
      (OLD.trusted_review_status = 'draft' AND NEW.trusted_review_status = 'pending')
      OR (OLD.trusted_review_status = 'pending' AND NEW.trusted_review_status IN ('approved','rejected'))
      OR (OLD.status = 'approved' AND OLD.trusted_review_status = 'draft' AND NEW.trusted_review_status = 'approved' AND NEW.trusted_review_migrated_from_approval)
    ) THEN
      RAISE EXCEPTION 'invalid trusted review transition';
    END IF;
  END IF;
  RETURN NEW;
END;
$$;
DROP TRIGGER IF EXISTS eg_training_trusted_review_guard_trg ON estimate_generation_training_datasets;
CREATE TRIGGER eg_training_trusted_review_guard_trg BEFORE INSERT OR UPDATE OF status, trusted_review_status, trusted_review_submitted_by, trusted_review_submitted_at, trusted_reviewed_by, trusted_reviewed_at ON estimate_generation_training_datasets FOR EACH ROW EXECUTE FUNCTION eg_training_trusted_review_guard();
SQL);

        $processed = 0;
        $idleAttempts = 0;
        while (true) {
            $affected = DB::affectingStatement(<<<'SQL'
UPDATE estimate_generation_training_datasets
SET trusted_review_status = 'approved',
    trusted_reviewed_by = approved_by,
    trusted_reviewed_at = approved_at,
    trusted_review_migrated_from_approval = true
WHERE id IN (
    SELECT id
    FROM estimate_generation_training_datasets
    WHERE dataset_type = 'development'
      AND status = 'approved'
      AND trusted_review_status = 'draft'
      AND approved_by IS NOT NULL
      AND approved_at IS NOT NULL
    ORDER BY id
    LIMIT 250
    FOR UPDATE SKIP LOCKED
)
SQL);
            $processed += $affected;
            Log::info('training_trusted_review_backfill_progress', [
                'batch_size' => $affected,
                'processed' => $processed,
            ]);
            if ($affected > 0) {
                $idleAttempts = 0;
                continue;
            }
            $remaining = (int) DB::scalar(<<<'SQL'
SELECT count(*)
FROM estimate_generation_training_datasets
WHERE dataset_type = 'development'
  AND status = 'approved'
  AND trusted_review_status = 'draft'
  AND approved_by IS NOT NULL
  AND approved_at IS NOT NULL
SQL);
            if ($remaining === 0) {
                break;
            }
            $idleAttempts++;
            Log::warning('training_trusted_review_backfill_stalled', [
                'remaining' => $remaining,
                'idle_attempt' => $idleAttempts,
            ]);
            if ($idleAttempts >= 30) {
                throw new \RuntimeException('training_trusted_review_backfill_stalled');
            }
            sleep(1);
        }

        DB::unprepared(<<<'SQL'
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'eg_training_trusted_submitter_fk') THEN
    ALTER TABLE estimate_generation_training_datasets ADD CONSTRAINT eg_training_trusted_submitter_fk FOREIGN KEY (trusted_review_submitted_by) REFERENCES system_admins(id) ON DELETE RESTRICT NOT VALID;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'eg_training_trusted_reviewer_fk') THEN
    ALTER TABLE estimate_generation_training_datasets ADD CONSTRAINT eg_training_trusted_reviewer_fk FOREIGN KEY (trusted_reviewed_by) REFERENCES system_admins(id) ON DELETE RESTRICT NOT VALID;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'eg_training_trusted_review_ck') THEN
    ALTER TABLE estimate_generation_training_datasets ADD CONSTRAINT eg_training_trusted_review_ck CHECK (control_version >= 0 AND trusted_review_status IN ('draft','pending','approved','rejected') AND ((trusted_review_status = 'draft' AND trusted_review_submitted_by IS NULL AND trusted_review_submitted_at IS NULL AND trusted_reviewed_by IS NULL AND trusted_reviewed_at IS NULL AND NOT trusted_review_migrated_from_approval) OR (trusted_review_status = 'pending' AND trusted_review_submitted_by IS NOT NULL AND trusted_review_submitted_at IS NOT NULL AND trusted_reviewed_by IS NULL AND trusted_reviewed_at IS NULL AND NOT trusted_review_migrated_from_approval) OR (trusted_review_status IN ('approved','rejected') AND trusted_review_submitted_by IS NOT NULL AND trusted_review_submitted_at IS NOT NULL AND trusted_reviewed_by IS NOT NULL AND trusted_reviewed_at IS NOT NULL AND trusted_review_submitted_by <> trusted_reviewed_by AND NOT trusted_review_migrated_from_approval) OR (trusted_review_status = 'approved' AND trusted_review_migrated_from_approval AND trusted_review_submitted_by IS NULL AND trusted_review_submitted_at IS NULL AND trusted_reviewed_by = approved_by AND trusted_reviewed_at = approved_at))) NOT VALID;
  END IF;
END $$;
SQL);
        DB::statement('RESET lock_timeout');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS eg_training_trusted_review_guard_trg ON estimate_generation_training_datasets; DROP FUNCTION IF EXISTS eg_training_trusted_review_guard();');
        }
    }
};
