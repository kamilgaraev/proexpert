<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReportingArtifactTransfer
{
    public string $artifactSha256;

    public function __construct(
        public string $artifactId,
        public string $kind,
        public string $status,
        public string $sourcePath,
        public string $destinationPath,
        public string $schemaPath,
        public string $releaseSha,
        public string $sourceCommitSha,
        public string $activationCommitSha,
        public ?string $adminEvidenceCommitSha,
        public DateTimeImmutable $generatedAt,
        public string $sourceSha256,
        public string $destinationSha256,
        public string $schemaSha256,
        public string $transferSchemaSha256,
        public string $schemaVersion = '1.0.0',
    )
    {
        $ids = ['activation' => 'report_catalog_activation_transfer', 'admin-evidence' => 'plan4_admin_evidence_transfer', 'release' => 'report_release_evidence_transfer'];
        if (($ids[$kind] ?? null) !== $artifactId || $status !== 'artifact_transferred' || $schemaVersion !== '1.0.0' || preg_match('/^[a-f0-9]{40}$/', $releaseSha) !== 1 || preg_match('/^[a-f0-9]{40}$/', $sourceCommitSha) !== 1 || preg_match('/^[a-f0-9]{40}$/', $activationCommitSha) !== 1 || ($adminEvidenceCommitSha !== null && preg_match('/^[a-f0-9]{40}$/', $adminEvidenceCommitSha) !== 1) || (($kind === 'activation') !== ($adminEvidenceCommitSha === null)) || (($kind === 'admin-evidence') && $sourceCommitSha !== $adminEvidenceCommitSha) || (in_array($kind, ['activation', 'release'], true) && $sourceCommitSha !== $activationCommitSha) || preg_match('/^[a-f0-9]{64}$/', $sourceSha256) !== 1 || preg_match('/^[a-f0-9]{64}$/', $destinationSha256) !== 1 || preg_match('/^[a-f0-9]{64}$/', $schemaSha256) !== 1 || preg_match('/^[a-f0-9]{64}$/', $transferSchemaSha256) !== 1) {
            throw new InvalidArgumentException('reporting_artifact_transfer_invalid');
        }

        $this->artifactSha256 = $sourceSha256;
    }
}
