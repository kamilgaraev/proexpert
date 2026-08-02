<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Registry;

use App\BusinessModules\Core\Reporting\Domain\Contracts\CandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationReadiness;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;

final class ProductionReportDefinitionRegistry implements ReportDefinitionRegistry
{
    private array $definitions = [];

    public function __construct(CandidateReportDefinitionRegistry $candidates)
    {
        foreach ($candidates->candidateCodes() as $code) {
            $candidate = $candidates->candidate($code)->definition;
            $this->definitions[$code] = new PublishedReportDefinition(new ReportDefinition(
                $candidate->code,
                $candidate->definitionHash,
                $candidate->contractVersion,
                $candidate->formulaVersion,
                $candidate->sourceSchemaVersion,
                $candidate->rendererVersion,
                $candidate->filters,
                $candidate->columns,
                $candidate->sorts,
                $candidate->formats,
                $candidate->permissionPolicy,
                $candidate->snapshotClassification,
                $candidate->outputClassification,
                ReportPublicationReadiness::PUBLISHED,
                $candidate->supportsSubscriptions,
            ));
        }
    }

    public function published(string $code): PublishedReportDefinition
    {
        return $this->definitions[$code]
            ?? throw \App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException::fromCode(
                \App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode::REPORT_NOT_FOUND,
            );
    }

    public function publishedCodes(): array
    {
        return array_keys($this->definitions);
    }

    public function manifestSha256(): Sha256Hash
    {
        return new Sha256Hash(hash('sha256', CanonicalJson::encode(array_map(
            static fn (PublishedReportDefinition $definition): string => $definition->definitionHash->value,
            $this->definitions,
        ))));
    }
}
