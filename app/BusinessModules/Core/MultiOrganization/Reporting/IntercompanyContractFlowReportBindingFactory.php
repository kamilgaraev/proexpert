<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting;

use App\BusinessModules\Core\MultiOrganization\Reporting\Providers\IntercompanyContractFlowsReportProvider;
use App\BusinessModules\Core\MultiOrganization\Reporting\Queries\IntercompanyContractFlowRowQuery;
use App\BusinessModules\Core\MultiOrganization\Reporting\Readiness\IntercompanyContractFlowReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use InvalidArgumentException;

final readonly class IntercompanyContractFlowReportBindingFactory
{
    public function __construct(
        private IntercompanyContractFlowsReportProvider $provider,
        private IntercompanyContractFlowRowQuery $rows,
        private IntercompanyContractFlowReadinessProbe $readiness,
        private IntercompanyContractFlowCandidateContract $contract,
    ) {}

    public function create(ReportDefinition $definition): ReportDefinitionBinding
    {
        $this->contract->assertRuntimeMatches();
        $this->contract->assertDefinition($definition);
        if (! $this->readiness->supports($definition)) {
            throw new InvalidArgumentException('intercompany_contract_flow_definition_not_supported');
        }

        return new ReportDefinitionBinding(
            $definition->code,
            $definition->definitionHash,
            $definition->contractVersion,
            $this->provider,
            $this->rows,
            $this->rows,
            $this->readiness,
        );
    }
}
