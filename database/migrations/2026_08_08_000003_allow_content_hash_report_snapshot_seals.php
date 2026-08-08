<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE report_snapshot_seals DROP CONSTRAINT report_snapshot_seals_crypto_check');
        DB::statement(<<<'SQL'
ALTER TABLE report_snapshot_seals
ADD CONSTRAINT report_snapshot_seals_integrity_check CHECK (
    key_id ~ '^[a-z][a-z0-9_.:-]{2,127}$'
    AND sealed_payload_hash ~ '^[a-f0-9]{64}$'
    AND (
        (algorithm = 'ed25519-sha256' AND signature ~ '^[A-Za-z0-9_-]{86}$')
        OR
        (algorithm = 'sha256' AND key_id = 'content_hash_v1' AND signature ~ '^[A-Za-z0-9_-]{43}$')
    )
)
SQL);

        DB::statement('ALTER TABLE report_runs DROP CONSTRAINT report_runs_snapshot_seal_check');
        DB::statement(<<<'SQL'
ALTER TABLE report_runs
ADD CONSTRAINT report_runs_snapshot_seal_check CHECK (
    (snapshot_seal_key_id IS NULL AND snapshot_seal_algorithm IS NULL AND snapshot_sealed_payload_hash IS NULL AND snapshot_seal_signature IS NULL AND snapshot_sealed_at IS NULL)
    OR
    (
        snapshot_seal_key_id IS NOT NULL
        AND snapshot_seal_key_id ~ '^[a-z][a-z0-9_.:-]{2,127}$'
        AND snapshot_sealed_payload_hash ~ '^[a-f0-9]{64}$'
        AND snapshot_sealed_at IS NOT NULL
        AND (
            (snapshot_seal_algorithm = 'ed25519-sha256' AND snapshot_seal_signature ~ '^[A-Za-z0-9_-]{86}$')
            OR
            (snapshot_seal_algorithm = 'sha256' AND snapshot_seal_key_id = 'content_hash_v1' AND snapshot_seal_signature ~ '^[A-Za-z0-9_-]{43}$')
        )
    )
)
SQL);

        DB::statement('ALTER TABLE report_exports DROP CONSTRAINT report_exports_snapshot_seal_check');
        DB::statement(<<<'SQL'
ALTER TABLE report_exports
ADD CONSTRAINT report_exports_snapshot_seal_check CHECK (
    (snapshot_seal_key_id IS NULL AND snapshot_seal_algorithm IS NULL AND snapshot_sealed_payload_hash IS NULL AND snapshot_seal_signature IS NULL AND snapshot_sealed_at IS NULL AND snapshot_classification = 'operational')
    OR
    (
        snapshot_seal_key_id IS NOT NULL
        AND snapshot_seal_key_id ~ '^[a-z][a-z0-9_.:-]{2,127}$'
        AND snapshot_sealed_payload_hash ~ '^[a-f0-9]{64}$'
        AND snapshot_sealed_at IS NOT NULL
        AND snapshot_classification = 'official'
        AND snapshot_sealed_at >= snapshot_generated_at
        AND (
            (snapshot_seal_algorithm = 'ed25519-sha256' AND snapshot_seal_signature ~ '^[A-Za-z0-9_-]{86}$')
            OR
            (snapshot_seal_algorithm = 'sha256' AND snapshot_seal_key_id = 'content_hash_v1' AND snapshot_seal_signature ~ '^[A-Za-z0-9_-]{43}$')
        )
    )
)
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('report_snapshot_content_hash_migration_irreversible');
    }
};
