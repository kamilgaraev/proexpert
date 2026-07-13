<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE estimate_generation_training_datasets ADD COLUMN IF NOT EXISTS control_version integer NOT NULL DEFAULT 0');
        DB::statement("ALTER TABLE estimate_generation_training_datasets ADD COLUMN IF NOT EXISTS trusted_review_status varchar(16) NOT NULL DEFAULT 'draft'");
        DB::statement('ALTER TABLE estimate_generation_training_datasets ADD COLUMN IF NOT EXISTS trusted_review_submitted_by bigint');
        DB::statement('ALTER TABLE estimate_generation_training_datasets ADD COLUMN IF NOT EXISTS trusted_review_submitted_at timestamptz');
        DB::statement('ALTER TABLE estimate_generation_training_datasets ADD COLUMN IF NOT EXISTS trusted_reviewed_by bigint');
        DB::statement('ALTER TABLE estimate_generation_training_datasets ADD COLUMN IF NOT EXISTS trusted_reviewed_at timestamptz');
        DB::statement('ALTER TABLE estimate_generation_training_datasets ADD CONSTRAINT eg_training_trusted_submitter_fk FOREIGN KEY (trusted_review_submitted_by) REFERENCES system_admins(id) ON DELETE RESTRICT NOT VALID');
        DB::statement('ALTER TABLE estimate_generation_training_datasets ADD CONSTRAINT eg_training_trusted_reviewer_fk FOREIGN KEY (trusted_reviewed_by) REFERENCES system_admins(id) ON DELETE RESTRICT NOT VALID');
        DB::statement("ALTER TABLE estimate_generation_training_datasets ADD CONSTRAINT eg_training_trusted_review_ck CHECK (control_version >= 0 AND trusted_review_status IN ('draft','pending','approved','rejected') AND ((trusted_review_status = 'draft' AND trusted_review_submitted_by IS NULL AND trusted_review_submitted_at IS NULL AND trusted_reviewed_by IS NULL AND trusted_reviewed_at IS NULL) OR (trusted_review_status = 'pending' AND trusted_review_submitted_by IS NOT NULL AND trusted_review_submitted_at IS NOT NULL AND trusted_reviewed_by IS NULL AND trusted_reviewed_at IS NULL) OR (trusted_review_status IN ('approved','rejected') AND trusted_review_submitted_by IS NOT NULL AND trusted_review_submitted_at IS NOT NULL AND trusted_reviewed_by IS NOT NULL AND trusted_reviewed_at IS NOT NULL AND trusted_review_submitted_by <> trusted_reviewed_by))) NOT VALID");
        DB::unprepared(<<<'SQL'
CREATE FUNCTION eg_training_trusted_review_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.dataset_type = 'development' AND NEW.status = 'approved' AND NEW.trusted_review_status <> 'approved' THEN
    RAISE EXCEPTION 'development dataset requires trusted review before approval';
  END IF;
  IF TG_OP = 'UPDATE' AND NEW.trusted_review_status IS DISTINCT FROM OLD.trusted_review_status THEN
    IF NOT ((OLD.trusted_review_status = 'draft' AND NEW.trusted_review_status = 'pending') OR (OLD.trusted_review_status = 'pending' AND NEW.trusted_review_status IN ('approved','rejected'))) THEN
      RAISE EXCEPTION 'invalid trusted review transition';
    END IF;
  END IF;
  RETURN NEW;
END;
$$;
CREATE TRIGGER eg_training_trusted_review_guard_trg BEFORE INSERT OR UPDATE OF status, trusted_review_status, trusted_review_submitted_by, trusted_review_submitted_at, trusted_reviewed_by, trusted_reviewed_at ON estimate_generation_training_datasets FOR EACH ROW EXECUTE FUNCTION eg_training_trusted_review_guard();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS eg_training_trusted_review_guard_trg ON estimate_generation_training_datasets; DROP FUNCTION IF EXISTS eg_training_trusted_review_guard();');
        }
    }
};
