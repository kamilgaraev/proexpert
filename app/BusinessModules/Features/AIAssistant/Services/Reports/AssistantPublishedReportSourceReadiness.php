<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Options\AcceptedProductionOptionsService;
use DateTimeImmutable;

final readonly class AssistantPublishedReportSourceReadiness
{
    public const REASONS = [
        'REPORT_SOURCE_UNAVAILABLE' => 'source_unavailable',
        'REPORT_SOURCE_INCOMPLETE' => 'source_incomplete',
        'REPORT_SOURCE_HISTORY_INCOMPLETE' => 'source_history_incomplete',
        'REPORT_SOURCE_REFERENCE_MISSING' => 'source_reference_missing',
    ];

    public function __construct(private AcceptedProductionOptionsService $acceptedProduction) {}

    public function check(ReportExecutionContext $context, array $parameters): ?string
    {
        if ($parameters['report_code'] !== 'accepted_production_progress') {
            return null;
        }
        $options = $this->acceptedProduction->options(
            $context->scope,
            $parameters['project_id'],
            new DateTimeImmutable($parameters['as_of']),
            new DateTimeImmutable($parameters['filters']['period_from'], $context->scope->timezone),
            new DateTimeImmutable($parameters['filters']['period_to'], $context->scope->timezone),
        );
        if ($options['available']) {
            return null;
        }

        return array_search($options['reason'], self::REASONS, true) ?: 'REPORT_SOURCE_UNAVAILABLE';
    }
}
