<?php

declare(strict_types=1);

use App\Contracts\Database\ForwardOnlyMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration implements ForwardOnlyMigration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE estimate_generation_vision_physical_attempts ADD COLUMN IF NOT EXISTS processing_lineage_id uuid NULL');
        DB::statement('ALTER TABLE estimate_generation_vision_physical_attempts ADD COLUMN IF NOT EXISTS logical_request_fingerprint char(64) NULL');
        DB::unprepared(<<<'SQL'
DO $$ BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'eg_vision_physical_logical_fingerprint_ck'
          AND conrelid = 'estimate_generation_vision_physical_attempts'::regclass
    ) THEN
        ALTER TABLE estimate_generation_vision_physical_attempts
        ADD CONSTRAINT eg_vision_physical_logical_fingerprint_ck
        CHECK (logical_request_fingerprint IS NULL OR logical_request_fingerprint ~ '^[0-9a-f]{64}$') NOT VALID;
    END IF;
END $$
SQL);
        DB::statement('ALTER TABLE estimate_generation_vision_physical_attempts VALIDATE CONSTRAINT eg_vision_physical_logical_fingerprint_ck');
        DB::statement(<<<'SQL'
CREATE INDEX IF NOT EXISTS eg_vision_physical_lineage_logical_idx
ON estimate_generation_vision_physical_attempts (
    organization_id,
    project_id,
    session_id,
    document_id,
    processing_lineage_id,
    logical_request_fingerprint
)
WHERE processing_lineage_id IS NOT NULL AND logical_request_fingerprint IS NOT NULL
SQL);
    }

    public function down(): void
    {
        throw new \RuntimeException('Vision physical identity separation is forward-only.');
    }
};
