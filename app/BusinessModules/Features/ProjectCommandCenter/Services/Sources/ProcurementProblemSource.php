<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProjectCommandCenter\Services\Sources;

use App\BusinessModules\Features\Procurement\Services\ProcurementIssueService;
use App\BusinessModules\Features\ProjectCommandCenter\DTO\ProjectProblemItem;
use App\BusinessModules\Features\ProjectCommandCenter\Services\ProjectProblemSource;
use App\Domain\Project\ValueObjects\ProjectContext;
use App\Models\Project;
use App\Modules\Core\AccessController;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;

final class ProcurementProblemSource implements ProjectProblemSource
{
    public function __construct(
        private readonly AccessController $accessController,
        private readonly ?Closure $issues = null,
        private readonly ?ProcurementIssueService $issueService = null,
    ) {
    }

    public function isAvailable(ProjectContext $projectContext): bool
    {
        return $projectContext->hasPermission('procurement.view')
            && $this->accessController->hasModuleAccess($projectContext->organizationId, 'procurement');
    }

    public function collect(Project $project, ProjectContext $projectContext): iterable
    {
        if ($project->getKey() !== $projectContext->projectId) {
            return [];
        }

        $issues = $this->issues === null
            ? $this->issueService?->forProject($projectContext->organizationId, $projectContext->projectId) ?? []
            : ($this->issues)($projectContext);

        return collect($issues)
            ->map(static fn (array $issue): ProjectProblemItem => new ProjectProblemItem(
                id: 'procurement-' . (string) $issue['id'],
                severity: self::severity((string) ($issue['severity'] ?? 'info')),
                module: 'procurement',
                title: (string) ($issue['title'] ?? ''),
                description: (string) ($issue['description'] ?? ''),
                impactTypes: ['procurement'],
                amount: null,
                dueAt: null,
                detectedAt: self::date($issue['created_at'] ?? null) ?? CarbonImmutable::now(),
                actionModule: 'procurement',
                actionRoute: self::actionRoute($issue['entity_href'] ?? null),
            ))
            ->all();
    }

    private static function severity(string $severity): string
    {
        return match ($severity) {
            'critical', 'blocker' => 'critical',
            'warning' => 'risk',
            default => 'attention',
        };
    }

    private static function actionRoute(mixed $route): ?string
    {
        return is_string($route) && preg_match('#^/procurement/(?:purchase-requests|purchase-orders)/\\d+$#', $route) === 1
            ? $route
            : null;
    }

    private static function date(mixed $value): ?DateTimeInterface
    {
        return $value instanceof DateTimeInterface ? $value : (is_string($value) ? CarbonImmutable::parse($value) : null);
    }
}
