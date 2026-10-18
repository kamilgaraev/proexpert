<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Core\Reporting\Application\Contracts\ReportFilterInputNormalizer;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AssistantPublishedReportSourceReadiness
{
    public const REASONS = [
        'REPORT_SOURCE_UNAVAILABLE' => 'source_unavailable',
        'REPORT_SOURCE_INCOMPLETE' => 'source_incomplete',
        'REPORT_SOURCE_HISTORY_INCOMPLETE' => 'source_history_incomplete',
        'REPORT_SOURCE_REFERENCE_MISSING' => 'source_reference_missing',
    ];

    public function __construct(
        private ReportDefinitionRegistry $definitions,
        private ReportDefinitionBindingMap $bindings,
        private ReportFilterInputNormalizer $filters,
    ) {}

    public function check(ReportExecutionContext $context, array $parameters): ?string
    {
        if ($parameters['report_code'] !== 'accepted_production_progress') {
            return null;
        }
        $definition = $this->definitions->published($parameters['report_code'])->payload();
        $probe = $this->bindings->get($definition->code)->readinessProbe;
        if (! $probe instanceof ReportSourceReadinessProbe) {
            return null;
        }
        $query = new ReportQuery($definition, $context->scope,
            $this->filters->forExecution($context, $definition, new ReportFilterSet($parameters['filters'])),
            $parameters['comparison'], new DateTimeImmutable($parameters['as_of']), $parameters['locale']);
        if ($query->filters->values['period_to'] > $query->asOf->setTimezone($context->scope->timezone)->format('Y-m-d')) {
            throw new InvalidArgumentException(trans_message('ai_published_reports.invalid'));
        }
        try {
            return $probe->inspect($context, $query)->isReady() ? null : 'REPORT_SOURCE_INCOMPLETE';
        } catch (ReportContractException $exception) {
            if ($exception->errorCode === ReportErrorCode::REPORT_FILTER_RANGE_INVALID) {
                return 'REPORT_SOURCE_HISTORY_INCOMPLETE';
            }
            throw $exception;
        }
    }
}
