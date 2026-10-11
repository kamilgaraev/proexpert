<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Evidence epochs require PostgreSQL.');
        }
        DB::statement('ALTER TABLE estimate_generation_evidence DROP CONSTRAINT eg_evidence_invalidation_ck');
        DB::statement('ALTER TABLE estimate_generation_evidence ADD CONSTRAINT eg_evidence_invalidation_ck CHECK (invalidation_version >= 0 AND ((invalidated_at IS NULL AND invalidation_reason IS NULL) OR (invalidated_at IS NOT NULL AND invalidation_reason IS NOT NULL AND invalidation_version > 0)))');
        DB::statement('ALTER TABLE estimate_generation_project_model_fact_evidence DROP CONSTRAINT estimate_generation_project_model_fact_evidence_pkey');
        DB::statement('ALTER TABLE estimate_generation_project_model_fact_evidence ADD PRIMARY KEY (fact_id, evidence_id, evidence_invalidation_version)');
        DB::unprepared(<<<'SQL'
CREATE FUNCTION eg_evidence_epoch_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.invalidation_version < OLD.invalidation_version
      OR (OLD.invalidated_at IS NULL AND NEW.invalidated_at IS NOT NULL AND NEW.invalidation_version <> OLD.invalidation_version + 1)
      OR (OLD.invalidated_at IS NOT NULL AND NEW.invalidated_at IS NULL AND NEW.invalidation_version <> OLD.invalidation_version) THEN
        RAISE EXCEPTION 'estimate_generation.evidence_epoch_not_monotonic';
    END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER eg_evidence_epoch_trg BEFORE UPDATE ON estimate_generation_evidence FOR EACH ROW EXECUTE FUNCTION eg_evidence_epoch_guard();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Evidence epoch history is forward-only.');
    }
};
