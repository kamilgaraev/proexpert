<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Application\SavedViews\ReportSavedViewService;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\CreateReportSubscriptionHandler;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\PauseReportSubscriptionHandler;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\ReportSubscriptionExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\ReportSubscriptionPeriodResolver;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\ResumeReportSubscriptionHandler;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\UpdateReportSubscriptionHandler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportCatalogMetadataRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSavedViewStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSchedulingCapabilityRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\CreateReportSubscriptionData;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscription;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionExecutionInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\UpdateReportSubscriptionData;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionFrequency;
use App\BusinessModules\Core\Reporting\Support\ReportExportColumnReferences;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Models\Organization;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class AssistantReportSubscriptionManager
{
    public const TOOLS = ['create_report_subscription', 'update_report_subscription'];

    public function __construct(
        private AssistantDataAccessPolicy $access,
        private OrganizationEntitlementService $entitlements,
        private ReportDefinitionRegistry $definitions,
        private ReportCatalogMetadataRegistry $metadata,
        private ReportSchedulingCapabilityRegistry $scheduling,
        private CurrentReportScopeAuthorizer $authorizer,
        private ReportExecutionContextFactory $contexts,
        private ReportSubscriptionExecutionContextFactory $subscriptionContexts,
        private ReportSubscriptionStore $subscriptions,
        private ReportSavedViewStore $views,
        private ReportSavedViewService $viewService,
        private ReportSubscriptionPeriodResolver $periods,
        private ReportExecutionClock $clock,
        private CreateReportSubscriptionHandler $createHandler,
        private UpdateReportSubscriptionHandler $updateHandler,
        private PauseReportSubscriptionHandler $pauseHandler,
        private ResumeReportSubscriptionHandler $resumeHandler,
    ) {}

    public function authorize(User $actor, int $organizationId, string $tool, array $arguments): void
    {
        if (! in_array($tool, self::TOOLS, true)) {
            $this->invalid();
        }
        $this->access->withCurrentChecks($actor, $organizationId, function () use ($actor, $organizationId): void {
            if ((int) $actor->current_organization_id !== $organizationId
                || ! $this->access->canReadDomain($actor, $organizationId, 'assistant')
                || ! $this->access->canCurrentPermission($actor, $organizationId, 'reports.view')
                || ! $this->access->canCurrentPermission($actor, $organizationId, 'reports.manage')
                || ! $this->entitlements->hasModuleAccess($organizationId, 'reports')) {
                throw new AuthorizationException;
            }
        }, true);
        if ($arguments === []) {
            return;
        }
        if ($tool === 'update_report_subscription') {
            $subscription = $this->own($actor, $organizationId, (string) ($arguments['subscription_id'] ?? ''));
            $this->subscriptionContexts->forSubscription($subscription, ReportOperation::MANAGE);
            $view = $this->views->getVisible($organizationId, (int) $actor->id, $subscription->savedViewId);
            if ($view->status !== 'active') {
                $this->invalid();
            }
        } elseif ($tool === 'create_report_subscription') {
            $definition = $this->definitions->published((string) ($arguments['report_code'] ?? ''))->payload();
            $this->context($actor, $organizationId, $definition, $this->projectId($arguments), new DateTimeZone((string) ($arguments['timezone'] ?? '')));
        } else {
            $this->invalid();
        }
    }

    public function lockTarget(array $arguments, User $actor, int $organizationId): array
    {
        $own = $this->own($actor, $organizationId, (string) ($arguments['subscription_id'] ?? ''));
        $locked = $this->subscriptions->lock($own->id);
        if ($locked->ownerId !== (int) $actor->id || $locked->organizationId !== $organizationId) {
            throw new AuthorizationException;
        }
        $this->subscriptionContexts->forSubscription($locked, ReportOperation::MANAGE);
        $this->views->lockVisible($organizationId, (int) $actor->id, $locked->savedViewId);

        return ['id' => $locked->id, 'version' => $locked->transitionVersion,
            'input_hash' => $locked->executionInputHash->value, 'status' => $locked->status->value];
    }

    public function snapshot(string $tool, array $arguments, User $actor, int $organizationId): array
    {
        $prepared = $this->prepare($tool, $arguments, $actor, $organizationId);
        $subscription = $prepared['subscription'];
        $view = $prepared['view'];

        return ['definition_hash' => $prepared['definition']->definitionHash->value,
            'scope' => $prepared['context']->scope->canonicalIdentity(),
            'subscription' => $subscription === null ? null : ['id' => $subscription->id, 'version' => $subscription->transitionVersion,
                'input_hash' => $subscription->executionInputHash->value, 'status' => $subscription->status->value],
            'view' => $view === null ? null : ['id' => $view->id, 'updated_at' => $view->updatedAt->format('Y-m-d\TH:i:s.uP'),
                'status' => $view->status, 'filters' => $view->filters->values, 'columns' => $view->columns,
                'sort' => [$view->sort->field, $view->sort->direction->value], 'comparison' => $view->comparison, 'name' => $view->name],
            'configuration' => $prepared['view_input'], 'schedule' => $prepared['schedule'],
            'display_before' => $subscription === null ? [] : $this->display($prepared, 'current'),
            'display_after' => $this->display($prepared, $prepared['operation'])];
    }

    public function create(array $arguments, User $actor, Organization $organization): array
    {
        return DB::transaction(function () use ($arguments, $actor, $organization): array {
            $prepared = $this->prepare('create_report_subscription', $arguments, $actor, (int) $organization->id);
            $view = $this->viewService->create($prepared['context'], $prepared['view_input']);
            $subscription = $this->createHandler->handle($prepared['context'], $this->scheduleData($view->id, $prepared['schedule']));

            return $this->result($subscription, 'created');
        });
    }

    public function update(array $arguments, User $actor, Organization $organization): array
    {
        return DB::transaction(function () use ($arguments, $actor, $organization): array {
            $this->lockTarget($arguments, $actor, (int) $organization->id);
            $prepared = $this->prepare('update_report_subscription', $arguments, $actor, (int) $organization->id);
            $id = $prepared['subscription']->id;
            if ($prepared['operation'] === 'pause') {
                return $this->result($this->pauseHandler->handle($prepared['context'], $id), 'paused');
            }
            if ($prepared['operation'] === 'resume') {
                return $this->result($this->resumeHandler->handle($prepared['context'], $id), 'resumed');
            }
            $view = $this->viewService->create($prepared['context'], $prepared['view_input']);
            $changes = $prepared['schedule'];
            unset($changes['period_mode']);
            $changes['saved_view_id'] = $view->id;
            $changes['timezone'] = new DateTimeZone($changes['timezone']);
            $changes['period_policy'] = ['mode' => $prepared['schedule']['period_mode']];
            $subscription = $this->updateHandler->handle($prepared['context'], $id, new UpdateReportSubscriptionData($changes));

            return $this->result($subscription, 'updated');
        });
    }

    private function prepare(string $tool, array $arguments, User $actor, int $organizationId): array
    {
        $this->authorize($actor, $organizationId, $tool, $arguments);
        $subscription = $tool === 'update_report_subscription'
            ? $this->own($actor, $organizationId, (string) ($arguments['subscription_id'] ?? '')) : null;
        $operation = $subscription === null ? 'create' : ($arguments['operation'] ?? '');
        if (! in_array($operation, ['create', 'update', 'pause', 'resume'], true)
            || ($operation !== 'create' && $tool !== 'update_report_subscription')) {
            $this->invalid();
        }
        if (in_array($operation, ['pause', 'resume'], true) && array_diff(array_keys($arguments), ['subscription_id', 'operation']) !== []) {
            $this->invalid();
        }
        if ($operation === 'update' && count($arguments) === 2) {
            $this->invalid();
        }
        $input = $subscription === null ? null : ReportSubscriptionExecutionInput::fromCanonicalBytes($subscription->executionInputBytes);
        $definition = $this->definitions->published($subscription?->reportCode ?? (string) ($arguments['report_code'] ?? ''))->payload();
        $view = $subscription === null ? null : $this->views->getVisible($organizationId, (int) $actor->id, $subscription->savedViewId);
        if ($view !== null && ($view->status !== 'active' || $view->contractVersion !== $definition->contractVersion || $view->reportCode !== $definition->code)) {
            $this->invalid();
        }
        if ($subscription !== null && in_array($operation, ['pause', 'resume'], true)) {
            $schedule = $this->storedSchedule($subscription);
            $context = $this->subscriptionContexts->forSubscription($subscription, ReportOperation::MANAGE, $input);
        } else {
            $schedule = $this->schedule($arguments, $subscription, $input);
            $context = $this->context($actor, $organizationId, $definition, $schedule['project_id'], new DateTimeZone($schedule['timezone']));
            $capability = $this->scheduling->published($definition->code);
            if (! $capability->supportsSubscriptions || ! $capability->reproducibleScheduledSnapshot
                || ! in_array($schedule['format'], $definition->formats, true)) {
                $this->invalid();
            }
        }
        $viewInput = null;
        if (in_array($operation, ['create', 'update'], true)) {
            $filters = AssistantReportSubscriptionFilters::resolve($arguments, $view?->filters->values ?? []);
            $filters = $this->periods->initialFilters($definition, $filters, ['mode' => $schedule['period_mode']], $this->clock->now(), $context->scope->timezone);
            $viewInput = ['report_code' => $definition->code, 'name' => $arguments['name'] ?? $view?->name ?? '',
                'visibility' => 'private', 'is_default' => false, 'filters' => $filters, 'comparison' => $view?->comparison ?? [],
                'columns' => $arguments['columns'] ?? $view?->columns ?? [],
                'sort' => $arguments['sort'] ?? ($view === null ? ['field' => $definition->sorts[0]['id'], 'direction' => $definition->sorts[0]['direction'] ?? 'asc']
                    : ['field' => $view->sort->field, 'direction' => $view->sort->direction->value])];
            $validated = $this->viewService->prepareCreate($context, $viewInput);
            if (array_diff($validated->columns, array_column(AssistantPublishedReportColumns::exportable($definition->code, $definition->columns), 'id')) !== []) {
                $this->invalid();
            }
            $references = ReportExportColumnReferences::all($validated->columns, $validated->sort->field);
            if ((! $context->visibility->canViewSensitive && $definition->outputClassification->requiresSensitiveForColumns($references))
                || (! $context->visibility->canViewAudit && $definition->outputClassification->requiresAuditForColumns($references))) {
                throw new AuthorizationException;
            }
        }

        return compact('subscription', 'operation', 'definition', 'view', 'schedule', 'context') + ['view_input' => $viewInput];
    }

    private function schedule(array $arguments, ?ReportSubscription $subscription, ?ReportSubscriptionExecutionInput $input): array
    {
        $frequency = $arguments['frequency'] ?? $subscription?->frequency->value;
        if (! is_string($frequency) || ReportSubscriptionFrequency::tryFrom($frequency) === null
            || (isset($arguments['project_id']) && ($arguments['clear_project_scope'] ?? false))) {
            $this->invalid();
        }
        $schedule = ['frequency' => $frequency,
            'weekday' => $frequency === 'weekly' ? ($arguments['weekday'] ?? $subscription?->weekday) : null,
            'day_of_month' => $frequency === 'monthly' ? ($arguments['day_of_month'] ?? $subscription?->dayOfMonth) : null,
            'local_time' => $arguments['local_time'] ?? $subscription?->localTime,
            'timezone' => $arguments['timezone'] ?? $subscription?->timezone->getName(),
            'period_mode' => $arguments['period_mode'] ?? $subscription?->periodPolicy['mode'] ?? null,
            'format' => $arguments['format'] ?? $subscription?->format,
            'project_id' => $subscription === null ? $this->projectId($arguments)
                : (($arguments['clear_project_scope'] ?? false) ? null : ($arguments['project_id'] ?? $input?->effectiveProjectId()))];
        if (! is_string($schedule['local_time']) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $schedule['local_time']) !== 1
            || ! is_string($schedule['timezone']) || ! in_array($schedule['timezone'], DateTimeZone::listIdentifiers(), true)
            || ! in_array($schedule['period_mode'], ReportSubscriptionPeriodResolver::MODES, true)
            || ! in_array($schedule['format'], ['csv', 'xlsx', 'pdf'], true)
            || ($frequency === 'weekly' && (! is_int($schedule['weekday']) || $schedule['weekday'] < 1 || $schedule['weekday'] > 7))
            || ($frequency === 'monthly' && (! is_int($schedule['day_of_month']) || $schedule['day_of_month'] < 1 || $schedule['day_of_month'] > 31))
            || (isset($arguments['weekday']) && $frequency !== 'weekly')
            || (isset($arguments['day_of_month']) && $frequency !== 'monthly')) {
            $this->invalid();
        }

        return $schedule;
    }

    private function storedSchedule(ReportSubscription $subscription): array
    {
        $input = ReportSubscriptionExecutionInput::fromCanonicalBytes($subscription->executionInputBytes);

        return ['frequency' => $subscription->frequency->value, 'weekday' => $subscription->weekday,
            'day_of_month' => $subscription->dayOfMonth, 'local_time' => $subscription->localTime,
            'timezone' => $subscription->timezone->getName(), 'period_mode' => $subscription->periodPolicy['mode'] ?? null,
            'format' => $subscription->format, 'project_id' => $input->effectiveProjectId()];
    }

    private function context(User $actor, int $organizationId, ReportDefinition $definition, ?int $projectId, DateTimeZone $timezone): ReportExecutionContext
    {
        if ($projectId !== null) {
            return $this->subscriptionContexts->forProject((int) $actor->id, $organizationId, $projectId, $timezone, $definition, ReportOperation::MANAGE);
        }
        if ($definition->code === 'accepted_production_progress') {
            $this->invalid();
        }

        return $this->contexts->fromCurrentAuthorization($this->authorizer->authorizeForOrganization((int) $actor->id, $organizationId, $timezone,
            new CurrentReportAuthorizationTarget($definition, ReportOperation::MANAGE, null)));
    }

    private function projectId(array $arguments): ?int
    {
        if (($arguments['scope'] ?? null) === 'organization' && ! array_key_exists('project_id', $arguments)) {
            return null;
        }
        if (($arguments['scope'] ?? null) !== 'project' || ! is_int($arguments['project_id'] ?? null) || $arguments['project_id'] < 1) {
            $this->invalid();
        }

        return $arguments['project_id'];
    }

    private function own(User $actor, int $organizationId, string $id): ReportSubscription
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $id) !== 1) {
            $this->invalid();
        }

        return $this->subscriptions->getForActor($organizationId, (int) $actor->id, $id);
    }

    private function scheduleData(string $viewId, array $schedule): CreateReportSubscriptionData
    {
        return new CreateReportSubscriptionData($viewId, ReportSubscriptionFrequency::from($schedule['frequency']),
            $schedule['weekday'], $schedule['day_of_month'], $schedule['local_time'], new DateTimeZone($schedule['timezone']),
            ['mode' => $schedule['period_mode']], $schedule['format'], $schedule['project_id']);
    }

    private function display(array $prepared, string $operation): array
    {
        $subscription = $prepared['subscription'];
        $schedule = $prepared['schedule'];
        if ($operation === 'current' && $subscription !== null) {
            $schedule = $this->storedSchedule($subscription);
        }
        $values = ['report' => trans_message($this->metadata->published($prepared['definition']->code)->titleKey),
            'name' => $operation === 'current' ? $prepared['view']?->name : ($prepared['view_input']['name'] ?? $prepared['view']?->name),
            'project' => $schedule['project_id'] === null ? trans_message('ai_report_subscriptions.organization')
                : trans_message('ai_report_subscriptions.project', ['id' => $schedule['project_id']]).' — '.(string) \App\Models\Project::query()->whereKey($schedule['project_id'])->value('name'),
            'frequency' => trans_message('ai_report_subscriptions.frequency.'.$schedule['frequency']),
            'local_time' => $schedule['local_time'], 'timezone' => $schedule['timezone'],
            'period' => in_array($schedule['period_mode'], ReportSubscriptionPeriodResolver::MODES, true)
                ? trans_message('ai_report_subscriptions.period.'.$schedule['period_mode']) : trans_message('ai_report_subscriptions.legacy_period'),
            'format' => strtoupper($schedule['format']), 'channel' => trans_message('ai_report_subscriptions.channel')];
        if ($schedule['weekday'] !== null) {
            $values['weekday'] = trans_message('ai_report_subscriptions.weekday.'.$schedule['weekday']);
        }
        if ($schedule['day_of_month'] !== null) {
            $values['day_of_month'] = (string) $schedule['day_of_month'];
        }
        if ($operation !== 'current') {
            $values['operation'] = trans_message('ai_report_subscriptions.operation.'.$operation);
        }
        if ($operation === 'current') {
            $values['status'] = trans_message('ai_report_subscriptions.status.'.$subscription->status->value);
        }
        $configuration = $operation === 'current' || $prepared['view_input'] === null
            ? ['columns' => $prepared['view']->columns, 'filters' => $prepared['view']->filters->values,
                'sort' => ['field' => $prepared['view']->sort->field, 'direction' => $prepared['view']->sort->direction->value]] : $prepared['view_input'];
        $values['columns'] = implode(', ', array_map(fn (string $field): string => $this->fieldLabel($prepared['definition'], $field), $configuration['columns']));
        $values['sort'] = $this->fieldLabel($prepared['definition'], $configuration['sort']['field']).' — '.trans_message('ai_report_subscriptions.sort.'.$configuration['sort']['direction']);
        $filters = [];
        foreach ($configuration['filters'] as $field => $filter) {
            if (is_array($filter) && isset($filter['operator'], $filter['value'])) {
                $value = $filter['value'];
                $operator = trans_message('ai_report_subscriptions.operators.'.$filter['operator']);
            } else {
                $value = $filter;
                $operator = '';
            }
            $filters[] = $this->fieldLabel($prepared['definition'], $field).': '.trim($operator.' '.(is_array($value)
                ? implode(', ', array_map(static fn ($item): string => is_scalar($item) ? (string) $item : '', $value)) : (string) $value));
        }
        $values['filters'] = $filters === [] ? trans_message('ai_report_subscriptions.no_filters') : implode('; ', $filters);
        $items = [];
        foreach ($values as $key => $value) {
            $items[] = ['label' => trans_message('ai_report_subscriptions.labels.'.$key), 'value' => (string) $value];
        }

        return $items;
    }

    private function fieldLabel(ReportDefinition $definition, string $field): string
    {
        foreach ([...$definition->columns, ...$definition->filters] as $metadata) {
            if ($metadata['id'] === $field) {
                $label = $metadata['labels']['ru'] ?? $metadata['label'] ?? null;
                if (is_string($label) && $label !== '') {
                    return $label;
                }
            }
        }
        foreach (['ai_report_subscriptions.fields.', 'ai_assistant_facts.fields.'] as $prefix) {
            $label = trans_message($prefix.$field);
            if ($label !== $prefix.$field) {
                return $label;
            }
        }

        return str_replace('_', ' ', $field);
    }

    private function result(ReportSubscription $subscription, string $message): array
    {
        return ['message' => trans_message('ai_report_subscriptions.'.$message), 'subscription_id' => $subscription->id,
            'status' => $subscription->status->value, 'next_run_at' => $subscription->nextRunAt?->setTimezone($subscription->timezone)->format(DATE_ATOM)];
    }

    private function invalid(): never
    {
        throw new InvalidArgumentException(trans_message('ai_report_subscriptions.invalid'));
    }
}
