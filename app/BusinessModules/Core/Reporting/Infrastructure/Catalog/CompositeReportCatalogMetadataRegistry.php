<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Catalog;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportCatalogMetadataRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;

final readonly class CompositeReportCatalogMetadataRegistry implements ReportCatalogMetadataRegistry
{
    public function __construct(private ReportCatalogMetadataRegistry $builtins, private ReportCatalogMetadataRegistry $database) {}

    public function published(string $code): ReportCatalogMetadata
    {
        try {
            return $this->builtins->published($code);
        } catch (ReportContractException $exception) {
            if ($exception->errorCode !== ReportErrorCode::REPORT_NOT_FOUND) {
                throw $exception;
            }

            return $this->database->published($code);
        }
    }
}
