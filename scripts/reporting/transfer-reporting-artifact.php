<?php

declare(strict_types=1);

use App\BusinessModules\Core\Reporting\Application\Evidence\ReportingArtifactTransferService;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $options = getopt('', [
        'kind:', 'source-root:', 'source:', 'schema:', 'source-commit::', 'release-sha:',
        'activation-commit:', 'admin-transfer::', 'destination-root:', 'generated-at:', 'check',
    ]);
    $required = ['kind', 'source-root', 'source', 'schema', 'release-sha', 'activation-commit', 'destination-root', 'generated-at'];
    foreach ($required as $name) {
        if (! isset($options[$name]) || ! is_string($options[$name])) {
            throw new InvalidArgumentException('reporting_artifact_transfer_invalid');
        }
    }
    if ($options['kind'] === 'admin-evidence' && array_key_exists('source-commit', $options)) {
        throw new InvalidArgumentException('reporting_artifact_transfer_invalid');
    }
    $sourceCommit = $options['kind'] === 'admin-evidence'
        ? ''
        : ($options['source-commit'] ?? $options['activation-commit']);
    if (! is_string($sourceCommit)) {
        throw new InvalidArgumentException('reporting_artifact_transfer_invalid');
    }
    $transfer = (new ReportingArtifactTransferService())->transfer(
        $options['kind'], $options['source-root'], $options['source'], $options['schema'], $sourceCommit,
        $options['release-sha'], $options['activation-commit'], null, $options['destination-root'],
        new \DateTimeImmutable($options['generated-at']), isset($options['check']),
    );
    $sourceArtifact = match ($transfer->kind) {
        'activation' => 'report_catalog_activation',
        'admin-evidence' => 'plan4_admin_evidence',
        'release' => 'report_release_evidence',
    };
    fwrite(STDOUT, 'reporting-artifact-transfer: '.$sourceArtifact.' artifact_transferred sha256='.$transfer->artifactSha256.PHP_EOL);
} catch (Throwable) {
    fwrite(STDERR, "quality-gate:invalid\n");
    exit(2);
}
