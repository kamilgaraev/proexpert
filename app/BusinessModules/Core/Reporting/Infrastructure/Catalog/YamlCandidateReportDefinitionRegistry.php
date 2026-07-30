<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Catalog;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\CandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\CandidateReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\LoadedReportManifest;

final class YamlCandidateReportDefinitionRegistry implements CandidateReportDefinitionRegistry
{
    private array $definitions = [];

    private array $codes = [];

    public function __construct(
        LoadedReportManifest $manifest,
        ReportDefinitionFactory $factory,
    ) {
        foreach ($manifest->definitions as $row) {
            $readiness = $row['readiness'] ?? null;
            if (! is_array($readiness) || ($readiness['publication'] ?? null) !== 'candidate') {
                continue;
            }

            $candidate = new CandidateReportDefinition($factory->fromManifest($row));
            $this->definitions[$candidate->code] = $candidate;
            $this->codes[] = $candidate->code;
        }
    }

    public function candidate(string $code): CandidateReportDefinition
    {
        return $this->definitions[$code]
            ?? throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
    }

    public function candidateCodes(): array
    {
        return $this->codes;
    }
}
