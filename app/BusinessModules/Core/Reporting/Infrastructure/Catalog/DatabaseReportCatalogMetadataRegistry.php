<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Catalog;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportCatalogMetadataRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;

final readonly class DatabaseReportCatalogMetadataRegistry implements ReportCatalogMetadataRegistry
{
    public function __construct(private ReportPublicationRegistry $publications, private ReportDefinitionRegistry $definitions, private ReportDefinitionFactory $factory) {}

    public function published(string $code): ReportCatalogMetadata
    {
        $this->definitions->published($code);
        $record = $this->publications->currentRecord($code);
        if ($record === null) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
        }
        $codes = $this->definitions->publishedCodes();
        $ordinal = array_search($code, $codes, true);
        if (! is_int($ordinal)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
        }

        return $this->factory->metadataFromManifest($record->candidateDocument, $ordinal);
    }
}
