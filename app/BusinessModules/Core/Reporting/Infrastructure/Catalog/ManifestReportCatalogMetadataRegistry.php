<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Catalog;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportCatalogMetadataRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\LoadedReportManifest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use LogicException;

final class ManifestReportCatalogMetadataRegistry implements ReportCatalogMetadataRegistry
{
    private array $metadata = [];

    public function __construct(
        LoadedReportManifest $manifest,
        ReportDefinitionFactory $factory,
        ReportDefinitionRegistry $published,
    ) {
        if ($manifest->bytesHash->value !== $published->manifestSha256()->value) {
            throw new LogicException('report_manifest_hash_mismatch');
        }

        $codes = [];
        foreach ($manifest->definitions as $ordinal => $row) {
            $readiness = $row['readiness'] ?? null;
            if (! is_array($readiness) || ($readiness['publication'] ?? null) !== 'published') {
                continue;
            }

            $metadata = $factory->metadataFromManifest($row, $ordinal);
            $this->metadata[$metadata->code] = $metadata;
            $codes[] = $metadata->code;
        }

        if ($codes !== $published->publishedCodes()) {
            throw new LogicException('report_manifest_published_code_set_mismatch');
        }
    }

    public function published(string $code): ReportCatalogMetadata
    {
        return $this->metadata[$code]
            ?? throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
    }
}
