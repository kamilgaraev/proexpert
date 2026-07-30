<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReportingArtifactTransfer
{
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
        public string $artifactSha256 = '',
        public string $schemaSha256 = '',
        public string $transferSchemaSha256 = '',
    )
    {
        $ids = ['activation' => 'report_catalog_activation_transfer', 'admin-evidence' => 'plan4_admin_evidence_transfer', 'release' => 'report_release_evidence_transfer'];
        if (($ids[$kind] ?? null) !== $artifactId || $status !== 'artifact_transferred' || preg_match('/^[a-f0-9]{40}$/', $releaseSha) !== 1 || preg_match('/^[a-f0-9]{40}$/', $sourceCommitSha) !== 1 || preg_match('/^[a-f0-9]{40}$/', $activationCommitSha) !== 1 || ($adminEvidenceCommitSha !== null && preg_match('/^[a-f0-9]{40}$/', $adminEvidenceCommitSha) !== 1) || (($kind === 'release') !== ($adminEvidenceCommitSha !== null)) || ($artifactSha256 !== '' && preg_match('/^[a-f0-9]{64}$/', $artifactSha256) !== 1) || ($schemaSha256 !== '' && preg_match('/^[a-f0-9]{64}$/', $schemaSha256) !== 1) || ($transferSchemaSha256 !== '' && preg_match('/^[a-f0-9]{64}$/', $transferSchemaSha256) !== 1)) {
            throw new InvalidArgumentException('reporting_artifact_transfer_invalid');
        }
    }
}
