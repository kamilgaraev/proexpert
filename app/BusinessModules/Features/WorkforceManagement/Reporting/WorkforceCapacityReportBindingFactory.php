<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;

final readonly class WorkforceCapacityReportBindingFactory
{
    public function __construct(private WorkforceCapacityProvider $provider, private WorkforceReportQueryService $query, private WorkforceCapacityCandidateContract $contract) {}

    public function create(ReportDefinition $definition): ReportDefinitionBinding
    {
        $this->contract->assertRuntimeMatches();
        $this->contract->assertDefinition($definition);

        return new ReportDefinitionBinding($definition->code, $definition->definitionHash, $definition->contractVersion, $this->provider, $this->query, $this->query, null);
    }
}
