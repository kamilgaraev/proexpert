<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Catalog;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSchedulingCapabilityRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\LoadedReportManifest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use LogicException;

final class ManifestReportSchedulingCapabilityRegistry implements ReportSchedulingCapabilityRegistry
{
    private array $capabilities = [];

    public function __construct(
        LoadedReportManifest $manifest,
        ReportDefinitionFactory $factory,
        ReportDefinitionRegistry $published,
    ) {
        if ($manifest->bytesHash->value !== $published->manifestSha256()->value) {
            throw new LogicException('report_manifest_hash_mismatch');
        }

        $codes = [];
        foreach ($manifest->definitions as $row) {
            $readiness = $row['readiness'] ?? null;
            if (! is_array($readiness) || ($readiness['publication'] ?? null) !== 'published') {
                continue;
            }

            $capability = $factory->schedulingFromManifest($row);
            $this->capabilities[$capability->code] = $capability;
            $codes[] = $capability->code;
        }

        if ($codes !== $published->publishedCodes()) {
            throw new LogicException('report_manifest_published_code_set_mismatch');
        }
    }

    public function published(string $code): ReportSchedulingCapability
    {
        return $this->capabilities[$code]
            ?? throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
    }
}
