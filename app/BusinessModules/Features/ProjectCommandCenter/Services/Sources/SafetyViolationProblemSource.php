<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProjectCommandCenter\Services\Sources;

use App\BusinessModules\Features\ProjectCommandCenter\DTO\ProjectProblemItem;
use App\BusinessModules\Features\ProjectCommandCenter\Services\ProjectProblemSource;
use App\BusinessModules\Features\SafetyManagement\Http\Resources\SafetyViolationResource;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyViolation;
use App\Domain\Project\ValueObjects\ProjectContext;
use App\Models\Project;
use Closure;
use DateTimeInterface;
use Illuminate\Http\Request;
use App\Modules\Core\AccessController;

final class SafetyViolationProblemSource implements ProjectProblemSource
{
    use ChecksProjectProblemVisibility;

    public function __construct(
        private readonly AccessController $accessController,
        private readonly ?Closure $violations = null,
        private readonly ?Closure $surface = null,
    ) {
    }

    public function isAvailable(ProjectContext $projectContext): bool
    {
        if (! $this->canViewProblemSource($projectContext, 'safety-management.view')) {
            return false;
        }

        foreach (['safety-management', 'project-management', 'file-management'] as $moduleSlug) {
            if (! $this->accessController->hasModuleAccess($projectContext->organizationId, $moduleSlug)) {
                return false;
            }
        }

        return true;
    }

    public function collect(Project $project, ProjectContext $projectContext): iterable
    {
        if ($project->getKey() !== $projectContext->projectId) {
            return [];
        }

        foreach ($this->violationsFor($project, $projectContext) as $violation) {
            $item = ProjectProblemItem::fromWorkflowSurface(
                id: 'safety-violation-' . $violation->getKey(),
                module: 'safety',
                title: (string) $violation->title,
                surface: $this->surfaceFor($violation),
                impactTypes: ['safety'],
                amount: null,
                dueAt: $this->date($violation->due_date),
                detectedAt: $this->date($violation->created_at) ?? now(),
                actionModule: 'safety',
            );

            if ($item !== null) {
                yield $item;
            }
        }
    }

    private function violationsFor(Project $project, ProjectContext $projectContext): iterable
    {
        if ($this->violations !== null) {
            return ($this->violations)($project, $projectContext);
        }

        return SafetyViolation::query()
            ->forOrganization($projectContext->organizationId)
            ->where('project_id', $projectContext->projectId)
            ->get();
    }

    private function surfaceFor(SafetyViolation $violation): array
    {
        if ($this->surface !== null) {
            return ($this->surface)($violation);
        }

        return (new SafetyViolationResource($violation))->toArray(new Request());
    }

    private function date(mixed $value): ?DateTimeInterface
    {
        return $value instanceof DateTimeInterface ? $value : null;
    }
}
