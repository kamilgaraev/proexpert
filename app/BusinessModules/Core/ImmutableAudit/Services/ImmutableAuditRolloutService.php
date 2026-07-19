<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\ImmutableAudit\Services;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

final class ImmutableAuditRolloutService
{
    public const PHASE_B_WRITER_VERSION = 2;

    public function installCompatibilityPhase(ConnectionInterface $connection): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->statement('CREATE SEQUENCE IF NOT EXISTS immutable_audit_sequence');
        $connection->statement("CREATE TABLE IF NOT EXISTS immutable_audit_rollout (singleton boolean PRIMARY KEY DEFAULT true CHECK (singleton), phase text NOT NULL CHECK (phase IN ('phase_a', 'phase_b')), writer_version integer NOT NULL, updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        $connection->statement("INSERT INTO immutable_audit_rollout (singleton, phase, writer_version) VALUES (true, 'phase_a', 1) ON CONFLICT (singleton) DO NOTHING");
        $connection->unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION immutable_audit_allocate_compatible_sequence() RETURNS bigint AS $$
DECLARE allocated bigint;
BEGIN
    LOCK TABLE immutable_audit_events IN SHARE ROW EXCLUSIVE MODE;
    SELECT COALESCE(MAX(sequence_id), 0) + 2 INTO allocated FROM immutable_audit_events;
    RETURN allocated;
END;
$$ LANGUAGE plpgsql VOLATILE;
CREATE OR REPLACE FUNCTION immutable_audit_allocate_sequence() RETURNS bigint AS $$
DECLARE rollout_phase text;
BEGIN
    SELECT phase INTO rollout_phase FROM immutable_audit_rollout WHERE singleton = true;
    IF rollout_phase = 'phase_b' THEN RETURN nextval('immutable_audit_sequence'); END IF;
    RETURN immutable_audit_allocate_compatible_sequence();
END;
$$ LANGUAGE plpgsql VOLATILE;
CREATE OR REPLACE FUNCTION immutable_audit_sync_sequence_after_insert() RETURNS trigger AS $$
BEGIN
    PERFORM setval('immutable_audit_sequence', GREATEST((SELECT last_value FROM immutable_audit_sequence), NEW.sequence_id), true);
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS immutable_audit_sequence_sync ON immutable_audit_events;
CREATE TRIGGER immutable_audit_sequence_sync AFTER INSERT ON immutable_audit_events
FOR EACH ROW EXECUTE FUNCTION immutable_audit_sync_sequence_after_insert();
SQL);
    }

    public function cutover(ConnectionInterface $connection, bool $enabled, int $confirmedWriterVersion): void
    {
        if (! $enabled || $confirmedWriterVersion !== self::PHASE_B_WRITER_VERSION) {
            throw new RuntimeException('immutable_audit_phase_b_writer_fence_not_confirmed');
        }
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('immutable_audit_phase_b_requires_postgresql');
        }
        $phase = $connection->table('immutable_audit_rollout')->where('singleton', true)->value('phase');
        if ($phase === 'phase_b') {
            return;
        }
        if ($phase !== 'phase_a') {
            throw new RuntimeException('immutable_audit_phase_a_not_installed');
        }
        $connection->statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS immutable_audit_source_event_aggregate_unique ON immutable_audit_events (organization_id, domain, subject_type, subject_id, source, source_event_id) WHERE source_event_id IS NOT NULL AND subject_type IS NOT NULL AND subject_id IS NOT NULL');
        $connection->statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS immutable_audit_source_event_legacy_unique ON immutable_audit_events (organization_id, domain, source, source_event_id) WHERE source_event_id IS NOT NULL AND (subject_type IS NULL OR subject_id IS NULL)');
        $connection->transaction(function () use ($connection): void {
            $connection->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['immutable_audit_phase_b']);
            $connection->statement('LOCK TABLE immutable_audit_events IN ACCESS EXCLUSIVE MODE');
            $connection->statement("SELECT setval('immutable_audit_sequence', GREATEST((SELECT last_value FROM immutable_audit_sequence), COALESCE((SELECT MAX(sequence_id) FROM immutable_audit_events), 1)), EXISTS (SELECT 1 FROM immutable_audit_events))");
            $connection->table('immutable_audit_rollout')->where('singleton', true)->update(['phase' => 'phase_b', 'writer_version' => self::PHASE_B_WRITER_VERSION, 'updated_at' => now()]);
            $connection->statement('DROP INDEX IF EXISTS immutable_audit_source_event_unique');
        });
    }
}
