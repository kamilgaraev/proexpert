<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Readiness;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceReadiness;
use App\BusinessModules\Features\ScheduleManagement\Models\WorkConstraint;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\WorkConstraintTransitionEvent;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services\LookaheadReadinessPolicyService;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Models\ScheduleTaskStateVersion;
use App\Models\ScheduleTask;
use App\Support\Reporting\ReportSourceReadinessFactory;
use InvalidArgumentException;

final readonly class LookaheadReadinessProbe implements ReportSourceReadinessProbe
{
    public function __construct(
        private LookaheadReadinessPolicyService $policies,
        private ReportSourceReadinessFactory $readiness,
    ) {
    }

    public function supports(ReportDefinition $definition): bool
    {
        return $definition->code === 'lookahead_readiness'
            && $definition->formulaVersion === 'lookahead_readiness.v1';
    }

    public function reportCodes(): array
    {
        return ['lookahead_readiness'];
    }

    public function inspect(
        ReportExecutionContext $context,
        ReportQuery $query,
    ): ReportSourceReadiness {
        $eligible = [];
        $projected = [];
        $gapCount = 0;
        try {
            $policySet = $this->policies->activeForProjects(
                $context->scope->organizationId,
                $context->scope->projectIds,
                $query->asOf,
            );
            foreach ($context->scope->projectIds as $projectId) {
                $policy = $policySet->forProject($projectId);
                $eligible[] = ['kind' => 'policy', 'project_id' => $projectId];
                $projected[] = [
                    'kind' => 'policy',
                    'project_id' => $projectId,
                    'source_hash' => $policy->sourceHash,
                ];
            }
        } catch (InvalidArgumentException) {
            foreach ($context->scope->projectIds as $projectId) {
                $eligible[] = ['kind' => 'policy', 'project_id' => $projectId];
                $gapCount++;
            }
        }

        $constraints = WorkConstraint::withTrashed()
            ->where('organization_id', $context->scope->organizationId)
            ->whereIn('project_id', $context->scope->projectIds)
            ->where('created_at', '<=', $query->asOf)
            ->orderBy('id')
            ->get();
        foreach ($constraints as $constraint) {
            $eligible[] = [
                'created_at' => $constraint->created_at?->format(DATE_ATOM),
                'kind' => 'constraint',
                'overridden_at' => $constraint->overridden_at?->format(DATE_ATOM),
                'resolved_at' => $constraint->resolved_at?->format(DATE_ATOM),
                'source_id' => (int) $constraint->id,
                'status' => (string) $constraint->status,
            ];
            $events = WorkConstraintTransitionEvent::query()
                ->where('organization_id', $context->scope->organizationId)
                ->where('constraint_id', $constraint->id)
                ->where('occurred_at', '<=', $query->asOf)
                ->orderBy('event_version')
                ->get();
            $requiresFinal = (string) $constraint->status !== 'open'
                && (
                    ($constraint->resolved_at !== null && $constraint->resolved_at->lessThanOrEqualTo($query->asOf))
                    || ($constraint->overridden_at !== null
                        && $constraint->overridden_at->lessThanOrEqualTo($query->asOf))
                );
            $currentStateUnproven = (string) $constraint->status !== 'open'
                && $constraint->resolved_at === null
                && $constraint->overridden_at === null
                && $events->count() < 2;
            if ($events->isEmpty() || ($requiresFinal && $events->count() < 2) || $currentStateUnproven) {
                $gapCount++;
                continue;
            }
            $projected[] = [
                'event_hashes' => $events->pluck('source_hash')->all(),
                'kind' => 'constraint',
                'source_id' => (int) $constraint->id,
            ];
        }

        $tasks = ScheduleTask::withTrashed()
            ->where('organization_id', $context->scope->organizationId)
            ->where('created_at', '<=', $query->asOf)
            ->whereHas('schedule', fn ($builder) => $builder
                ->whereIn('project_id', $context->scope->projectIds)
                ->where('is_template', false))
            ->orderBy('id')
            ->get(['id']);
        $stateRows = ScheduleTaskStateVersion::query()
            ->where('organization_id', $context->scope->organizationId)
            ->whereIn('project_id', $context->scope->projectIds)
            ->where('effective_at', '<=', $query->asOf)
            ->whereIn('task_id', $tasks->pluck('id'))
            ->orderBy('task_id')
            ->orderByDesc('effective_at')
            ->orderByDesc('version')
            ->get()
            ->unique('task_id')
            ->keyBy('task_id');
        foreach ($tasks as $task) {
            $eligible[] = ['kind' => 'schedule_task_state', 'source_id' => (int) $task->id];
            $state = $stateRows->get((int) $task->id);
            if ($state === null) {
                $gapCount++;
                continue;
            }
            $projected[] = [
                'kind' => 'schedule_task_state',
                'source_hash' => (string) $state->source_hash,
                'source_id' => (int) $task->id,
            ];
        }

        $watermark = implode('.', [
            'lookahead:'.(int) ($constraints->max('id') ?? 0),
            (int) (WorkConstraintTransitionEvent::query()
                ->where('organization_id', $context->scope->organizationId)
                ->whereIn('project_id', $context->scope->projectIds)
                ->max('id') ?? 0),
            (int) ($stateRows->max('id') ?? 0),
        ]);

        return $this->readiness->make($eligible, $projected, $gapCount, 0, $watermark);
    }
}
