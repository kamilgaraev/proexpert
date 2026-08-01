<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementCycleReportBindingFactory;
use InvalidArgumentException;

final readonly class ProcurementCycleReleaseBindingFactoryAdapter implements ReportPublicationReleaseBindingFactory
{
    public function __construct(private ProcurementCycleReportBindingFactory $bindings) {}

    public function create(ReportDefinition $definition): ReportDefinitionBinding
    {
        if ($definition->code !== 'procurement_cycle') {
            throw new InvalidArgumentException('report_publication_release_request_untrusted');
        }

        return $this->bindings->create($definition);
    }
}
