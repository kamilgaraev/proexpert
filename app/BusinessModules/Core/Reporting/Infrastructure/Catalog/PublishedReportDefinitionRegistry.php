<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Catalog;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\LoadedReportManifest;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;

final class PublishedReportDefinitionRegistry implements ReportDefinitionRegistry
{
    private array $definitions = [];

    private array $codes = [];

    public function __construct(
        private LoadedReportManifest $manifest,
        ReportDefinitionFactory $factory,
    ) {
        foreach ($manifest->definitions as $row) {
            $readiness = $row['readiness'] ?? null;
            if (! is_array($readiness) || ($readiness['publication'] ?? null) !== 'published') {
                continue;
            }

            $published = new PublishedReportDefinition($factory->fromManifest($row));
            $this->definitions[$published->code] = $published;
            $this->codes[] = $published->code;
        }
    }

    public function published(string $code): PublishedReportDefinition
    {
        return $this->definitions[$code]
            ?? throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
    }

    public function publishedCodes(): array
    {
        return $this->codes;
    }

    public function manifestSha256(): Sha256Hash
    {
        return $this->manifest->bytesHash;
    }
}
