<?php

declare(strict_types=1);

namespace App\Services\Customer\Reporting\Sla;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\Services\Customer\Reporting\Sla\DrillDown\CustomerSlaDrillDownProvider;
use App\Services\Customer\Reporting\Sla\Providers\CustomerSlaReportProvider;
use App\Services\Customer\Reporting\Sla\Queries\CustomerSlaRowQuery;
use App\Services\Customer\Reporting\Sla\Readiness\CustomerSlaReadinessProbe;

final readonly class CustomerSlaReportBindingFactory
{
    public function __construct(private CustomerSlaReportProvider $provider, private CustomerSlaRowQuery $query, private CustomerSlaDrillDownProvider $drillDown, private CustomerSlaReadinessProbe $readiness, private CustomerSlaCandidateContract $contract) {}

    public function create(ReportDefinition $definition): ReportDefinitionBinding
    {
        $this->contract->assertRuntimeMatches();
        $this->contract->assertDefinition($definition);

        return new ReportDefinitionBinding($definition->code, $definition->definitionHash, $definition->contractVersion, $this->provider, $this->query, $this->drillDown, $this->readiness);
    }
}
