<?php

declare(strict_types=1);

use App\BusinessModules\Core\ImmutableAudit\DTO\ImmutableAuditEventData;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditIntegrityService;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRecorder;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRedactor;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRolloutService;
use Illuminate\Database\Capsule\Manager as Capsule;

require dirname(__DIR__, 3).'/vendor/autoload.php';

[$script, $schema, $action, $sourceEventId, $mode, $barrier] = array_pad($argv, 6, null);
$dsn = getenv('LEGAL_DOCUMENT_PG_TEST_DSN');
if (! is_string($schema) || preg_match('/^legal_doc_it_[a-f0-9]{12}$/D', $schema) !== 1 || ! is_string($dsn)) {
    exit(2);
}
$parts = [];
foreach (explode(';', preg_replace('/^pgsql:/', '', $dsn) ?? '') as $pair) {
    [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
    if (is_string($key) && is_string($value)) {
        $parts[$key] = $value;
    }
}
$database = new Capsule;
$database->addConnection([
    'driver' => 'pgsql',
    'host' => $parts['host'] ?? '127.0.0.1',
    'port' => $parts['port'] ?? '5432',
    'database' => $parts['dbname'] ?? '',
    'username' => (string) getenv('LEGAL_DOCUMENT_PG_TEST_USER'),
    'password' => (string) getenv('LEGAL_DOCUMENT_PG_TEST_PASSWORD'),
    'charset' => 'utf8',
    'prefix' => '',
]);
$database->setAsGlobal();
$database->bootEloquent();
$connection = $database->getConnection();
$connection->statement("SET search_path TO {$schema}");
if ($mode === 'cutover') {
    (new ImmutableAuditRolloutService)->cutover(
        $connection,
        true,
        ImmutableAuditRolloutService::PHASE_B_WRITER_VERSION,
        'test-immutable-audit-writer-token-2026-07-19',
        1,
    );
    exit(0);
}
if (in_array($mode, ['legacy', 'legacy_after', 'legacy_expiry_boundary'], true)) {
    $connection->transaction(function () use ($connection, $action, $sourceEventId, $mode, $barrier): void {
        $sequence = ((int) $connection->table('immutable_audit_events')->max('sequence_id')) + 1;
        if ($mode === 'legacy') {
            if (! is_string($barrier) || preg_match('/^[a-f0-9]{24}$/D', $barrier) !== 1) {
                throw new RuntimeException('invalid_barrier');
            }
            $ready = sys_get_temp_dir().DIRECTORY_SEPARATOR."most-audit-{$barrier}.ready";
            $release = sys_get_temp_dir().DIRECTORY_SEPARATOR."most-audit-{$barrier}.release";
            file_put_contents($ready, 'ready', LOCK_EX);
            while (! is_file($release)) {
                time_nanosleep(0, 10_000_000);
            }
        }
        if ($mode === 'legacy_expiry_boundary') {
            $connection->statement("UPDATE immutable_audit_rollout SET phase_a_expires_at = clock_timestamp() + INTERVAL '100 milliseconds' WHERE singleton = true");
            usleep(200_000);
        }
        $now = now()->setMicrosecond(0);
        $connection->table('immutable_audit_events')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'sequence_id' => $sequence,
            'organization_id' => 7, 'project_id' => 3, 'domain' => 'legal_archive',
            'event_type' => 'legacy.'.(string) $action, 'action' => (string) $action,
            'result' => 'success', 'severity' => 'info', 'occurred_at' => $now, 'recorded_at' => $now,
            'actor_type' => 'system', 'source' => 'legacy', 'source_event_id' => (string) $sourceEventId,
            'subject_type' => 'legacy_probe', 'subject_id' => '1', 'redaction_policy_version' => 'v1',
            'payload_hash' => str_repeat('a', 64), 'record_hash' => str_repeat('b', 64),
            'chain_scope' => 'legacy-probe', 'chain_version' => 1, 'integrity_status' => 'pending',
            'retention_until' => $now->copy()->addYears(7), 'created_at' => $now,
        ]);
    });
    exit(0);
}
$recorder = new ImmutableAuditRecorder(
    new ImmutableAuditRedactor,
    new ImmutableAuditIntegrityService,
    $connection,
    'test-immutable-audit-writer-token-2026-07-19',
);
$record = static fn (string $eventAction, string $eventSource, int $subjectId) => $recorder->record(new ImmutableAuditEventData(
    organizationId: 7, domain: 'legal_archive', eventType: 'legal_document.'.$eventAction,
    action: $eventAction, source: 'legal_archive', projectId: 3, sourceEventId: $eventSource,
    subjectType: 'legal_document', subjectId: $subjectId, afterState: ['status' => $eventAction],
    chainScope: "organization:7:legal_document:{$subjectId}",
));
if ($mode === 'batch') {
    $event = $connection->transaction(function () use ($record, $action, $sourceEventId) {
        $record((string) $action, (string) $sourceEventId, 99);

        return $record((string) $action.'_second', (string) $sourceEventId.':second', 100);
    });
} else {
    $event = $record((string) $action, (string) $sourceEventId, 99);
}

fwrite(STDOUT, (string) $event->id);
