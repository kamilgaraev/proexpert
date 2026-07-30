<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReportingArtifactTransfer
{
    public function __construct(public string $artifactId, public string $kind, public string $status, public string $sourcePath, public string $destinationPath, public string $schemaPath, public string $releaseSha, public string $sourceCommitSha, public string $activationCommitSha, public ?string $adminEvidenceCommitSha, public DateTimeImmutable $generatedAt)
    {
        $ids = ['activation' => 'report_catalog_activation_transfer', 'admin-evidence' => 'plan4_admin_evidence_transfer', 'release' => 'report_release_evidence_transfer'];
        if (($ids[$kind] ?? null) !== $artifactId || $status !== 'artifact_transferred' || preg_match('/^[a-f0-9]{40}$/', $releaseSha) !== 1 || preg_match('/^[a-f0-9]{40}$/', $sourceCommitSha) !== 1 || preg_match('/^[a-f0-9]{40}$/', $activationCommitSha) !== 1 || (($kind === 'release') !== ($adminEvidenceCommitSha !== null))) {
            throw new InvalidArgumentException('reporting_artifact_transfer_invalid');
        }
    }
}
