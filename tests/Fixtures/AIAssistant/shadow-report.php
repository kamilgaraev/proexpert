<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use App\Services\Credits\AICreditReadinessService;

$policy = require dirname(__DIR__, 3).'/config/ai-assistant-credits.php';
$readiness = new AICreditReadinessService($policy);
if (($argv[1] ?? '') === '--fixture') {
    $traces = ['schema_version' => 1, 'stage' => 'synthetic', 'policy_hash' => $readiness->policyFingerprint(), 'period' => ['started_at' => '2026-09-28T00:00:00Z', 'ended_at' => '2026-09-29T00:00:00Z'], 'assistant_revenue_minor' => 0, 'cost_coverage' => ['assistant' => false, 'memory' => false, 'index' => false, 'errors' => false, 'ocr' => false], 'scenarios' => require __DIR__.'/shadow-scenarios.php', 'background_calls' => []];
    echo json_encode($traces, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION).PHP_EOL;
    exit(0);
}
try {
    $report = $readiness->reportFromFile($argv[1] ?? '');
    echo json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION).PHP_EOL;
    exit($report['ready_for_approval'] ? 0 : 1);
} catch (InvalidArgumentException|JsonException $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(2);
}
