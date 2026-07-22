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
use Illuminate\Support\Facades\Schema;

final class QualityDefectProblemSource implements ProjectProblemSource
{
    public function __construct(private readonly AccessController $accessController)
    {
    }

    public function isAvailable(ProjectContext $projectContext): bool
    {
        return Schema::hasTable('quality_defects')
            && $projectContext->hasPermission('quality-control.view')
            && $this->accessController->hasModuleAccess($projectContext->organizationId, 'quality-control');
    }

    public function collect(Project $project, ProjectContext $projectContext): iterable
    {
        if ($project->getKey() !== $projectContext->projectId) {
            return [];
        }

        return DB::table('quality_defects')
            ->where('project_id', $projectContext->projectId)
            ->where('organization_id', $projectContext->organizationId)
            ->whereNull('deleted_at')
            ->where('severity', 'critical')
            ->whereNotIn('status', ['resolved', 'verified', 'closed', 'cancelled'])
            ->orderBy('due_date')
            ->get(['id', 'defect_number', 'title', 'due_date', 'created_at'])
            ->map(static fn (object $defect): ProjectProblemItem => new ProjectProblemItem(
                id: 'quality-defect-' . $defect->id,
                severity: 'critical',
                module: 'quality',
                title: (string) ($defect->title ?? $defect->defect_number ?? ('#' . $defect->id)),
                description: trans_message('project_command_center.problems.quality_defect_critical'),
                impactTypes: ['quality', 'schedule'],
                amount: null,
                dueAt: self::date($defect->due_date),
                detectedAt: self::date($defect->created_at) ?? CarbonImmutable::now(),
                actionModule: 'quality',
            ))
            ->all();
    }

    private static function date(mixed $value): ?DateTimeInterface
    {
        return $value instanceof DateTimeInterface ? $value : (is_string($value) ? CarbonImmutable::parse($value) : null);
    }
}
