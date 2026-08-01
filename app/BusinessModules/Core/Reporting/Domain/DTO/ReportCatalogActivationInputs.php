<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

final readonly class ReportCatalogActivationInputs
{
    public function __construct(public LoadedReportManifest $candidateManifest, public ReportCandidateValidationResult $validation, public array $candidateBindings, public array $conformanceEvidence, public array $planEvidenceDocuments)
    {
    }
}
