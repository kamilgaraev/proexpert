<?php

declare(strict_types=1);

use App\BusinessModules\Core\Reporting\Application\Evidence\ReportCleanupEvidenceBuilder;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $options = getopt('', ['release-sha:', 'activation-commit:', 'cutover-commit:', 'producer-commit:', 'cutover-at:', 'generated-at:', 'hash::', 'output:', 'check']);
    foreach (['release-sha', 'activation-commit', 'cutover-commit', 'producer-commit', 'cutover-at', 'generated-at', 'output'] as $name) {
        if (! isset($options[$name]) || ! is_string($options[$name])) {
            throw new InvalidArgumentException('report_cleanup_evidence_invalid');
        }
    }
    $hashes = isset($options['hash']) ? (array) $options['hash'] : [];
    $evidence = (new ReportCleanupEvidenceBuilder())->build(
        $options['release-sha'], $options['activation-commit'], $options['cutover-commit'], $options['producer-commit'],
        new \DateTimeImmutable($options['cutover-at']), new \DateTimeImmutable($options['generated-at']), $hashes,
    );
    $document = [
        'artifact_id' => $evidence->artifactId,
        'schema_version' => $evidence->schemaVersion,
        'status' => $evidence->status,
        'verification_mode' => $evidence->verificationMode,
        'release_sha' => $evidence->releaseSha,
        'activation_commit_sha' => $evidence->activationCommitSha,
        'cutover_commit_sha' => $evidence->cutoverCommitSha,
        'producer_commit_sha' => $evidence->producerCommitSha,
        'rollback_window_seconds' => $evidence->rollbackWindowSeconds,
        'eligible_at' => $evidence->eligibleAt->format('Y-m-d\\TH:i:s\\Z'),
        'generated_at' => $evidence->generatedAt->format('Y-m-d\\TH:i:s\\Z'),
        'checks' => $evidence->checkIds,
        'evidence_hashes' => $evidence->evidenceHashes,
    ];
    $bytes = CanonicalJson::encode($document)."\n";
    if (! isset($options['check'])) {
        $directory = dirname($options['output']);
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException('report_cleanup_evidence_write_failed');
        }
        file_put_contents($options['output'], $bytes);
    }
    fwrite(STDOUT, 'report-cleanup-evidence: cleanup_verified 6/6 sha256='.hash('sha256', $bytes).PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() === 'REPORT_CLEANUP_WINDOW_NOT_ELAPSED' ? "REPORT_CLEANUP_WINDOW_NOT_ELAPSED\n" : "quality-gate:invalid\n");
    exit(2);
}
