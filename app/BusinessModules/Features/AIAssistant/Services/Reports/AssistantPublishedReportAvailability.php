<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Core\Reporting\Application\Contracts\ReportFilterInputNormalizer;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRunReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use DateTimeImmutable;

final readonly class AssistantPublishedReportAvailability
{
    public function __construct(
        private ReportDefinitionRegistry $definitions,
        private ReportDefinitionBindingMap $bindings,
        private ReportFilterInputNormalizer $filters,
        private AssistantPublishedReportSourceReadiness $acceptedProduction,
        private AssistantPortfolioReportReadiness $portfolio,
    ) {}

    public function inspect(ReportExecutionContext $context, array $parameters): array
    {
        $read = fn (): array => $this->inspectSource($context, $parameters);

        return app()->bound(AssistantRequestExecutionContext::class)
            ? app(AssistantRequestExecutionContext::class)->withOperationBudget($read, 30_000) : $read();
    }

    private function inspectSource(ReportExecutionContext $context, array $parameters): array
    {
        $definition = $this->definitions->published($parameters['report_code'])->payload();
        $probe = $this->bindings->get($definition->code)->readinessProbe;
        if (! $probe instanceof ReportSourceReadinessProbe && ! $probe instanceof ReportRunReadinessProbe) {
            return $this->result('unchecked');
        }
        try {
            if ($definition->code === 'accepted_production_progress') {
                $reason = $this->acceptedProduction->check($context, $parameters);

                return $reason === null ? $this->result('ready') : $this->result('not_ready', AssistantPublishedReportSourceReadiness::REASONS[$reason] ?? 'source_unavailable');
            }
            $query = new ReportQuery($definition, $context->scope,
                $this->filters->forExecution($context, $definition, new ReportFilterSet($parameters['filters'])),
                $parameters['comparison'], new DateTimeImmutable($parameters['as_of']), $parameters['locale']);
            if ($probe instanceof ReportSourceReadinessProbe) {
                $readiness = $probe->inspect($context, $query);
                if (! $readiness->isReady()) {
                    $result = $this->result('not_ready', 'source_unavailable');
                    if ($definition->code === 'project_portfolio_health') {
                        $components = $this->portfolio->inspect($context, $query, $readiness->watermark);
                        if ($components !== []) {
                            $result['components'] = $components;
                        }
                    }

                    return $result;
                }
            } else {
                $probe->assertReady($context, $query);
            }

            return $this->result('ready');
        } catch (ReportContractException $exception) {
            if ($exception->errorCode !== ReportErrorCode::REPORT_SOURCE_UNAVAILABLE) {
                throw $exception;
            }

            return $this->result('not_ready', 'source_unavailable');
        }
    }

    private function result(string $state, ?string $reason = null): array
    {
        return ['state' => $state, 'failure_reason' => $reason];
    }
}
