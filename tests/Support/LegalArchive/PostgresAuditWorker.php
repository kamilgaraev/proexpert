<?php

declare(strict_types=1);

use App\BusinessModules\Core\ImmutableAudit\DTO\ImmutableAuditEventData;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditIntegrityService;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRecorder;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRedactor;
use Illuminate\Database\Capsule\Manager as Capsule;

require dirname(__DIR__, 3).'/vendor/autoload.php';

[$script, $schema, $action, $sourceEventId] = array_pad($argv, 4, null);
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
