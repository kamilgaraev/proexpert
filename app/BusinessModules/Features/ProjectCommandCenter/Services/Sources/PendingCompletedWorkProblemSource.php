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

final class PendingCompletedWorkProblemSource implements ProjectProblemSource
{
    public function __construct(private readonly AccessController $accessController)
    {
    }

    public function isAvailable(ProjectContext $projectContext): bool
    {
        return $projectContext->hasPermission('completed_works.view')
            && $this->accessController->hasModuleAccess($projectContext->organizationId, 'workflow-management');
    }

    public function collect(Project $project, ProjectContext $projectContext): iterable
    {
        if ($project->getKey() !== $projectContext->projectId) {
            return [];
        }

        return DB::table('completed_works')
            ->where('project_id', $projectContext->projectId)
            ->whereNull('deleted_at')
            ->whereIn('status', ['pending', 'in_review'])
            ->orderBy('created_at')
            ->get(['id', 'notes', 'created_at', 'updated_at'])
            ->map(static fn (object $work): ProjectProblemItem => new ProjectProblemItem(
                id: 'completed-work-' . $work->id,
                severity: 'attention',
                module: 'completed_work',
                title: (string) ($work->notes ?: ('#' . $work->id)),
                description: 'project_command_center.problems.completed_work_pending',
                impactTypes: ['workflow'],
                amount: null,
                dueAt: null,
                detectedAt: self::date($work->created_at) ?? CarbonImmutable::now(),
                actionModule: 'completed_work',
            ))
            ->all();
    }

    private static function date(mixed $value): ?DateTimeInterface
    {
        return $value instanceof DateTimeInterface ? $value : (is_string($value) ? CarbonImmutable::parse($value) : null);
    }
}
