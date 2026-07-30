<?php

declare(strict_types=1);

use App\BusinessModules\Core\Reporting\Application\Evidence\ReportingArtifactTransferService;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportingArtifactTransfer;

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
    $adminTransfer = match ($options['kind']) {
        'release' => loadAdminTransfer($options['admin-transfer'] ?? null),
        'activation', 'admin-evidence' => null,
        default => throw new InvalidArgumentException('reporting_artifact_transfer_invalid'),
    };
    if ($options['kind'] !== 'release' && array_key_exists('admin-transfer', $options)) {
        throw new InvalidArgumentException('reporting_artifact_transfer_invalid');
    }
    $transfer = (new ReportingArtifactTransferService())->transfer(
        $options['kind'], $options['source-root'], $options['source'], $options['schema'], $sourceCommit,
        $options['release-sha'], $options['activation-commit'], $adminTransfer, $options['destination-root'],
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

function loadAdminTransfer(mixed $path): ReportingArtifactTransfer
{
    if (! is_string($path)) {
        throw new InvalidArgumentException('reporting_artifact_transfer_invalid');
    }
    $bytes = file_get_contents($path);
    if ($bytes === false) {
        throw new InvalidArgumentException('reporting_artifact_transfer_invalid');
    }
    $descriptor = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($descriptor)) {
        throw new InvalidArgumentException('reporting_artifact_transfer_invalid');
    }
    $fields = [
        'artifact_id', 'schema_version', 'kind', 'status', 'source_path', 'destination_path', 'schema_path',
        'release_sha', 'source_commit_sha', 'activation_commit_sha', 'admin_evidence_commit_sha', 'generated_at',
        'source_sha256', 'destination_sha256', 'schema_sha256', 'transfer_schema_sha256',
    ];
    foreach ($fields as $field) {
        if (! isset($descriptor[$field]) || ! is_string($descriptor[$field])) {
            throw new InvalidArgumentException('reporting_artifact_transfer_invalid');
        }
    }

    return new ReportingArtifactTransfer(
        $descriptor['artifact_id'],
        $descriptor['kind'],
        $descriptor['status'],
        $descriptor['source_path'],
        $descriptor['destination_path'],
        $descriptor['schema_path'],
        $descriptor['release_sha'],
        $descriptor['source_commit_sha'],
        $descriptor['activation_commit_sha'],
        $descriptor['admin_evidence_commit_sha'],
        new \DateTimeImmutable($descriptor['generated_at']),
        $descriptor['source_sha256'],
        $descriptor['destination_sha256'],
        $descriptor['schema_sha256'],
        $descriptor['transfer_schema_sha256'],
        $descriptor['schema_version'],
    );
}
