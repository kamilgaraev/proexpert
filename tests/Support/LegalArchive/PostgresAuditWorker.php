<?php

declare(strict_types=1);

use App\BusinessModules\Core\ImmutableAudit\DTO\ImmutableAuditEventData;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditIntegrityService;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRecorder;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRedactor;
use Illuminate\Database\Capsule\Manager as Capsule;

require dirname(__DIR__, 3).'/vendor/autoload.php';

[$script, $schema, $action, $sourceEventId, $mode] = array_pad($argv, 5, null);
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
if ($mode === 'legacy') {
    $connection->transaction(function () use ($connection, $action, $sourceEventId): void {
        $sequence = ((int) $connection->table('immutable_audit_events')->max('sequence_id')) + 1;
        usleep(400_000);
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
$event = (new ImmutableAuditRecorder(
    new ImmutableAuditRedactor,
    new ImmutableAuditIntegrityService,
    $connection,
))->record(new ImmutableAuditEventData(
    organizationId: 7,
    domain: 'legal_archive',
    eventType: 'legal_document.'.(string) $action,
    action: (string) $action,
    source: 'legal_archive',
    projectId: 3,
    sourceEventId: (string) $sourceEventId,
    subjectType: 'legal_document',
    subjectId: 99,
    afterState: ['status' => $action],
    chainScope: 'organization:7:legal_document:99',
));

fwrite(STDOUT, (string) $event->id);
