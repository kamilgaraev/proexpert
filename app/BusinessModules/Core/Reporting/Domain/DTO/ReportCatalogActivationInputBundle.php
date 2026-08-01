<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReportCatalogActivationInputBundle
{
    public function __construct(public string $artifactId, public string $status, public string $releaseSha, public array $sourceArtifacts, public array $candidateManifest, public array $candidatePayloads, public array $validationItems, public array $bindings, public array $conformanceRecords, public array $planEvidenceDocuments, public array $counts, public array $sectionHashes, public DateTimeImmutable $generatedAt)
    {
        if ($artifactId !== 'report_catalog_activation_inputs' || $status !== 'activation_inputs_passed' || preg_match('/^[a-f0-9]{40}$/', $releaseSha) !== 1 || ($counts['candidate_payloads'] ?? null) !== 28 || ($counts['bindings'] ?? null) !== 28 || ($counts['conformance_records'] ?? null) !== 28) {
            throw new InvalidArgumentException('report_catalog_activation_input_bundle_invalid');
        }
    }
}
