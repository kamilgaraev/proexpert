<?php

declare(strict_types=1);

namespace Tests\Support;

use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditPhaseBInvariantService;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRolloutService;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditWriterCredential;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

trait EnablesImmutableAuditWriter
{
    protected function enableImmutableAuditWriter(): void
    {
        $secret = (string) config('legal_archive.audit_writer_secret');
        $this->enableImmutableAuditWriterOn(DB::connection(), $secret);
    }

    protected function enableImmutableAuditWriterOn(ConnectionInterface $connection, string $secret): void
    {
        $invariants = new ImmutableAuditPhaseBInvariantService;

        (new ImmutableAuditRolloutService)->installCompatibilityPhase($connection, 1, $secret);
        $connection->statement(<<<'SQL'
CREATE UNIQUE INDEX IF NOT EXISTS immutable_audit_source_event_aggregate_unique
ON immutable_audit_events (organization_id, domain, subject_type, subject_id, source, source_event_id)
WHERE source_event_id IS NOT NULL AND subject_type IS NOT NULL AND subject_id IS NOT NULL
SQL);
        $connection->statement(<<<'SQL'
CREATE UNIQUE INDEX IF NOT EXISTS immutable_audit_source_event_legacy_unique
ON immutable_audit_events (organization_id, domain, source, source_event_id)
WHERE source_event_id IS NOT NULL AND (subject_type IS NULL OR subject_id IS NULL)
SQL);
        $connection->table('immutable_audit_rollout')
            ->where('singleton', true)
            ->update([
                'phase' => 'phase_b',
                'writer_version' => 2,
                'writer_credential_hash' => (new ImmutableAuditWriterCredential)->fingerprint($secret),
            ]);
        $invariants->assertPermanentInvariants($connection);
    }
}
