<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Vision\PhysicalAttempt\EloquentVisionPhysicalAttemptStore;
use Illuminate\Database\PostgresConnection;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$encoded = getenv('MOST_CI_VISION_ATTEMPT_WORKER_PAYLOAD');
$json = is_string($encoded) ? base64_decode($encoded, true) : false;
$payload = is_string($json) ? json_decode($json, true, 32, JSON_THROW_ON_ERROR) : null;
if (! is_array($payload)) {
    throw new RuntimeException('Missing PostgreSQL claim worker payload.');
}
$configuration = $payload['configuration'] ?? null;
$context = $payload['context'] ?? null;
$schema = $payload['schema'] ?? null;
if (! is_array($configuration) || ! is_array($context) || ! is_string($schema)
    || preg_match('/^most_ci_vision_attempt_[a-f0-9]{16}$/D', $schema) !== 1) {
    throw new RuntimeException('Invalid PostgreSQL claim worker payload.');
}

$pdo = new PDO(
    (string) ($configuration['dsn'] ?? ''),
    (string) ($configuration['user'] ?? ''),
    (string) ($configuration['password'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$connection = new PostgresConnection($pdo, (string) ($configuration['database_ack'] ?? ''));
$connection->statement("SET statement_timeout TO '5000ms'");
$connection->statement("SET lock_timeout TO '5000ms'");
$connection->unprepared('SET search_path TO "'.$schema.'"');
$backendPid = (int) $connection->selectOne('SELECT pg_backend_pid() AS pid')->pid;
fwrite(STDOUT, 'READY:'.$backendPid.PHP_EOL);
fflush(STDOUT);

$snapshot = (new EloquentVisionPhysicalAttemptStore($connection))->claim(
    new AiOperationContext(
        (string) ($context['correlationId'] ?? ''),
        (string) ($context['attemptId'] ?? ''),
        (int) ($context['organizationId'] ?? 0),
        (int) ($context['projectId'] ?? 0),
        (int) ($context['sessionId'] ?? 0),
        (string) ($context['stage'] ?? ''),
        (string) ($context['operation'] ?? ''),
        (int) ($context['attemptOrdinal'] ?? 0),
        isset($context['documentId']) ? (int) $context['documentId'] : null,
        isset($context['pageId']) ? (int) $context['pageId'] : null,
        isset($context['unitId']) ? (int) $context['unitId'] : null,
    ),
    (string) ($payload['fingerprint'] ?? ''),
    (string) ($payload['owner_token'] ?? ''),
    new DateTimeImmutable((string) ($payload['now'] ?? '')),
    new DateTimeImmutable((string) ($payload['lease_expires_at'] ?? '')),
);

fwrite(STDOUT, json_encode([
    'state' => $snapshot->state,
    'owner_token' => $snapshot->ownerToken,
], JSON_THROW_ON_ERROR).PHP_EOL);
