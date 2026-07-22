<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProjectCommandCenter\Services\Sources;

use App\BusinessModules\Features\ProjectCommandCenter\DTO\ProjectProblemItem;
use App\BusinessModules\Features\ProjectCommandCenter\Services\ProjectProblemSource;
use App\Domain\Project\ValueObjects\ProjectContext;
use App\Models\Project;
use App\Modules\Core\AccessController;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

final class OverdueScheduleProblemSource implements ProjectProblemSource
{
    use ChecksProjectProblemVisibility;

    public function __construct(private readonly AccessController $accessController)
    {
    }

    public function isAvailable(ProjectContext $projectContext): bool
    {
        return $this->canViewProblemSource($projectContext, 'schedule.view')
            && $this->accessController->hasModuleAccess($projectContext->organizationId, 'schedule-management');
    }

    public function collect(Project $project, ProjectContext $projectContext): iterable
    {
        if ($project->getKey() !== $projectContext->projectId) {
            return [];
        }

        return DB::table('schedule_tasks as task')
            ->join('project_schedules as schedule', 'schedule.id', '=', 'task.schedule_id')
            ->where('schedule.project_id', $projectContext->projectId)
            ->where('schedule.organization_id', $projectContext->organizationId)
            ->where('task.organization_id', $projectContext->organizationId)
            ->whereNull('schedule.deleted_at')
            ->whereNull('task.deleted_at')
            ->whereNotIn('task.task_type', ['summary', 'container'])
            ->whereNotIn('task.status', ['completed', 'cancelled'])
            ->whereDate('task.planned_end_date', '<', CarbonImmutable::now()->toDateString())
            ->orderBy('task.planned_end_date')
            ->get(['task.id', 'task.name', 'task.planned_end_date', 'task.created_at', 'task.priority'])
            ->map(static fn (object $task): ProjectProblemItem => new ProjectProblemItem(
                id: 'schedule-task-' . $task->id,
                severity: $task->priority === 'critical' ? 'critical' : 'risk',
                module: 'schedule',
                title: (string) $task->name,
                description: trans_message('project_command_center.problems.schedule_task_overdue'),
                impactTypes: ['schedule'],
                amount: null,
                dueAt: self::date($task->planned_end_date),
                detectedAt: self::date($task->created_at) ?? CarbonImmutable::now(),
                actionModule: 'schedule',
            ))
            ->all();
    }

    private static function date(mixed $value): ?DateTimeInterface
    {
        return $value instanceof DateTimeInterface ? $value : (is_string($value) ? CarbonImmutable::parse($value) : null);
    }
}
