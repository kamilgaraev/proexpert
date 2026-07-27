<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;

final readonly class ReportDefinitionBinding
{
    public function __construct(
        public string $code,
        public Sha256Hash $definitionHash,
        public string $contractVersion,
        public ReportDataProvider $dataProvider,
        public ReportRowQuery $rowQuery,
        public ReportDrillDownProvider $drillDownProvider,
        public ?ReportDefinitionReadinessProbe $readinessProbe,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/', $code) !== 1 || trim($contractVersion) === '') {
            throw new InvalidArgumentException('report_definition_binding_invalid');
        }
    }
}
