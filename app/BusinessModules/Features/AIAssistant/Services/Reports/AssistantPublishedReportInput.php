<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Core\Reporting\Application\Subscriptions\ReportSubscriptionPeriodResolver;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportCatalogMetadataRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Support\ReportExportColumnReferences;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final readonly class AssistantPublishedReportInput
{
    public function __construct(
        private ReportDefinitionRegistry $definitions,
        private ReportCatalogMetadataRegistry $metadata,
        private AssistantPublishedReportAuthorization $authorization,
        private AssistantPublishedReportFilterInput $filters,
        private ReportSubscriptionPeriodResolver $periods,
    ) {}

    public function prepare(array $arguments, User $actor, int $organizationId, DateTimeImmutable $asOf): array
    {
        $code = $arguments['report_code'] ?? null;
        $scope = $arguments['scope'] ?? null;
        $projectId = $arguments['project_id'] ?? null;
        $timezone = $arguments['timezone'] ?? null;
        $format = $arguments['format'] ?? null;
        if (! is_string($code) || ! in_array($scope, ['project', 'organization'], true)
            || ($scope === 'project' && (! is_int($projectId) || $projectId < 1))
            || ($scope === 'organization' && $projectId !== null)
            || ! is_string($timezone) || ! in_array($timezone, DateTimeZone::listIdentifiers(), true)
            || ! in_array($format, ['csv', 'xlsx', 'pdf'], true)) {
            $this->invalid();
        }
        $definition = $this->definitions->published($code)->payload();
        if (! in_array($format, $definition->formats, true) || ($code === 'accepted_production_progress' && $projectId === null)) {
            $this->invalid();
        }
        $title = $arguments['title'] ?? trans_message($this->metadata->published($code)->titleKey);
        if (! is_string($title) || trim($title) === '' || mb_strlen($title) > 120) {
            $this->invalid();
        }
        $parameters = ['report_code' => $code, 'definition_sha256' => $definition->definitionHash->value,
            'title' => trim($title), 'project_id' => $projectId, 'timezone' => $timezone, 'format' => $format, 'locale' => 'ru-RU',
            'as_of' => $asOf->format('Y-m-d\TH:i:s.uP')];
        $context = $this->authorization->createRun((int) $actor->id, $organizationId, $parameters);
        if (! $context->visibility->canExport || ! $context->visibility->canDownload) {
            throw new AuthorizationException;
        }
        $columns = $definition->validatedSelectedColumnIds($arguments['columns'] ?? []);
        if (array_diff($columns, array_column(AssistantPublishedReportColumns::exportable($code, $definition->columns), 'id')) !== []) {
            $this->invalid();
        }
        $sort = $arguments['sort'] ?? ['field' => $definition->sorts[0]['id'], 'direction' => $definition->sorts[0]['direction'] ?? 'asc'];
        if (! is_array($sort) || ! is_string($sort['field'] ?? null) || ! is_string($sort['direction'] ?? null)
            || ! in_array($sort['field'], array_column($definition->sorts, 'id'), true)
            || ReportSortDirection::tryFrom($sort['direction']) === null) {
            $this->invalid();
        }
        $references = ReportExportColumnReferences::all($columns, $sort['field']);
        if ((! $context->visibility->canViewSensitive && $definition->outputClassification->requiresSensitiveForColumns($references))
            || (! $context->visibility->canViewAudit && $definition->outputClassification->requiresAuditForColumns($references))) {
            throw new AuthorizationException;
        }
        $mode = $arguments['period_mode'] ?? 'fixed_filters';
        if (! in_array($mode, ReportSubscriptionPeriodResolver::MODES, true)) {
            $this->invalid();
        }
        $filters = $this->filters->parse($arguments['filters_json'] ?? '{}');
        $filters = $this->periods->initialFilters($definition, $filters, ['mode' => $mode], $asOf, new DateTimeZone($timezone));
        $filters = $this->filters->normalize($context, $definition, $filters, $asOf);

        return $parameters + ['columns' => $columns, 'filters' => $filters->values,
            'sort' => ['field' => $sort['field'], 'direction' => $sort['direction']], 'comparison' => []];
    }

    private function invalid(): never
    {
        throw new InvalidArgumentException(trans_message('ai_published_reports.invalid'));
    }
}
