<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProjectCommandCenter\Services\Sources;

use App\BusinessModules\Features\ProjectCommandCenter\DTO\ProjectProblemItem;
use App\BusinessModules\Features\ProjectCommandCenter\Services\ProjectProblemSource;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Domain\Project\ValueObjects\ProjectContext;
use App\Models\Project;
use App\Modules\Core\AccessController;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;

final class OverdueSiteRequestProblemSource implements ProjectProblemSource
{
    public function __construct(
        private readonly AccessController $accessController,
        private readonly ?Closure $requests = null,
        private readonly ?Closure $description = null,
    ) {
    }

    public function isAvailable(ProjectContext $projectContext): bool
    {
        return $projectContext->hasPermission('site_requests.view')
            && $this->accessController->hasModuleAccess($projectContext->organizationId, 'site-requests');
    }

    public function collect(Project $project, ProjectContext $projectContext): iterable
    {
        if ($project->getKey() !== $projectContext->projectId) {
            return [];
        }

        $requests = $this->requests === null
            ? SiteRequest::query()
                ->forOrganization($projectContext->organizationId)
                ->forProject($projectContext->projectId)
                ->active()
                ->overdue()
                ->orderBy('required_date')
                ->get(['id', 'title', 'required_date', 'created_at'])
            : ($this->requests)();

        return collect($requests)
            ->map(fn (mixed $request): ProjectProblemItem => new ProjectProblemItem(
                id: 'site-request-' . self::value($request, 'id'),
                severity: 'risk',
                module: 'site_requests',
                title: (string) self::value($request, 'title'),
                description: $this->description === null
                    ? trans_message('project_command_center.problems.site_request_overdue')
                    : ($this->description)(),
                impactTypes: ['procurement', 'schedule'],
                amount: null,
                dueAt: self::date(self::value($request, 'required_date')),
                detectedAt: self::date(self::value($request, 'created_at')) ?? CarbonImmutable::now(),
                actionModule: 'site_requests',
                actionRoute: '/site-requests/' . self::value($request, 'id'),
            ))
            ->all();
    }

    private static function value(mixed $item, string $attribute): mixed
    {
        return is_array($item) ? ($item[$attribute] ?? null) : $item->{$attribute};
    }

    private static function date(mixed $value): ?DateTimeInterface
    {
        return $value instanceof DateTimeInterface ? $value : (is_string($value) ? CarbonImmutable::parse($value) : null);
    }
}
