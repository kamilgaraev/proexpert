<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Features\ProjectCommandCenter;

use App\BusinessModules\Features\ProjectCommandCenter\DTO\ProjectProblemItem;
use App\BusinessModules\Features\ProjectCommandCenter\Services\ProjectProblemCollector;
use App\BusinessModules\Features\ProjectCommandCenter\Services\ProjectProblemSource;
use App\BusinessModules\Features\ProjectCommandCenter\Services\Sources\SafetyViolationProblemSource;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyViolation;
use App\Domain\Project\ValueObjects\ProjectContext;
use App\Domain\Project\ValueObjects\ProjectRoleConfig;
use App\Enums\ProjectOrganizationRole;
use App\Models\Project;
use App\Modules\Core\AccessController;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ProjectProblemCollectorTest extends TestCase
{
    public function test_it_orders_normalised_module_problems_and_preserves_safe_actions(): void
    {
        $collector = new ProjectProblemCollector([
            new FixtureProblemSource(true, [
                $this->problem('safety-violation-3', 'critical', 'safety', 'safety', 1500.0, '2026-07-20', '2026-07-19'),
                $this->problem('schedule-task-7', 'warning', 'schedule', 'schedule', 20000.0, '2026-07-18', '2026-07-20'),
                $this->problem('completed-work-5', 'warning', 'completed_work', 'completed_work', 80000.0, null, '2026-07-20'),
                $this->problem('material-8', 'warning', 'materials', 'materials', 10000.0, null, '2026-07-18'),
            ]),
        ]);

        $result = $collector->collect($this->project(), $this->context(), new DateTimeImmutable('2026-07-21T12:00:00+03:00'));

        self::assertSame(['safety-violation-3', 'schedule-task-7', 'completed-work-5', 'material-8'], array_column($result['items'], 'id'));
        self::assertSame(['total' => 4, 'critical' => 1, 'risk' => 3, 'attention' => 0], $result['summary']);
        self::assertSame('/safety/violations', $result['items'][0]['action']['route']);
        self::assertSame(['project_id' => 42], $result['items'][0]['action']['query']);
        self::assertArrayNotHasKey('url', $result['items'][0]['action']);
    }

    public function test_it_omits_unavailable_and_unauthorised_sources(): void
    {
        $collector = new ProjectProblemCollector([
            new FixtureProblemSource(false, [$this->problem('safety-violation-3', 'critical', 'safety', 'safety', null, null, '2026-07-19')]),
        ]);

        self::assertSame([
            'summary' => ['total' => 0, 'critical' => 0, 'risk' => 0, 'attention' => 0],
            'items' => [],
        ], $collector->collect($this->project(), $this->context(), new DateTimeImmutable('2026-07-21T12:00:00+03:00')));

        $unauthorisedCollector = new ProjectProblemCollector([
            new FixtureProblemSource(true, [$this->problem('material-8', 'warning', 'materials', 'materials', null, null, '2026-07-19')]),
        ]);

        self::assertSame([], $unauthorisedCollector->collect(
            $this->project(),
            $this->context([]),
            new DateTimeImmutable('2026-07-21T12:00:00+03:00'),
        )['items']);

        $foreignProject = $this->project();
        $foreignProject->setAttribute('id', 43);

        self::assertSame([], $collector->collect($foreignProject, $this->context(), new DateTimeImmutable())['items']);
    }

    public function test_it_collects_existing_safety_problem_flags_with_project_scoped_action(): void
    {
        $violation = new SafetyViolation([
            'id' => 18,
            'project_id' => 42,
            'organization_id' => 7,
            'title' => 'Ограждение не установлено',
            'due_date' => new DateTimeImmutable('2026-07-20T00:00:00+03:00'),
            'created_at' => new DateTimeImmutable('2026-07-19T10:00:00+03:00'),
        ]);
        $violation->setAttribute('id', 18);
        $captured = [];
        $source = new SafetyViolationProblemSource(
            accessController: $this->activeSafetyModules(),
            violations: static function (Project $project, ProjectContext $projectContext) use ($violation, &$captured): array {
                $captured = [
                    'project_id' => $project->getKey(),
                    'organization_id' => $projectContext->organizationId,
                ];

                return [$violation];
            },
            surface: static fn (): array => [
                'problem_flags' => [[
                    'severity' => 'critical',
                    'message' => 'Нарушение не устранено в срок.',
                ]],
                'workflow_summary' => ['status_label' => 'Открыто'],
            ],
        );

        $result = (new ProjectProblemCollector([$source]))->collect(
            $this->project(),
            $this->context(['safety-management.view']),
            new DateTimeImmutable('2026-07-21T12:00:00+03:00'),
        );

        self::assertSame('safety-violation-18', $result['items'][0]['id']);
        self::assertSame('/safety/violations', $result['items'][0]['action']['route']);
        self::assertSame(['project_id' => 42], $result['items'][0]['action']['query']);
        self::assertSame(['project_id' => 42, 'organization_id' => 7], $captured);
    }

    public function test_safety_source_requires_all_active_module_dependencies(): void
    {
        $source = new SafetyViolationProblemSource(
            new class extends AccessController {
                public function hasModuleAccess(int $organizationId, string $moduleSlug): bool
                {
                    return $organizationId === 7 && $moduleSlug !== 'file-management';
                }
            },
        );

        self::assertFalse($source->isAvailable($this->context(['safety-management.view'])));
    }

    private function problem(
        string $id,
        string $flagSeverity,
        string $module,
        string $actionModule,
        ?float $amount,
        ?string $dueAt,
        string $detectedAt,
    ): ProjectProblemItem {
        return ProjectProblemItem::fromWorkflowSurface(
            id: $id,
            module: $module,
            title: 'Проблема на объекте',
            surface: [
                'problem_flags' => [[
                    'severity' => $flagSeverity,
                    'message' => 'Требуется действие руководителя проекта.',
                ]],
                'workflow_summary' => ['status_label' => 'Открыто'],
            ],
            impactTypes: ['schedule'],
            amount: $amount,
            dueAt: $dueAt === null ? null : new DateTimeImmutable($dueAt . 'T00:00:00+03:00'),
            detectedAt: new DateTimeImmutable($detectedAt . 'T00:00:00+03:00'),
            actionModule: $actionModule,
        ) ?? throw new \LogicException('Fixture must provide a problem flag.');
    }

    private function project(): Project
    {
        $project = new Project();
        $project->setAttribute('id', 42);

        return $project;
    }

    private function context(array $permissions = ['problems.view']): ProjectContext
    {
        $role = ProjectOrganizationRole::OWNER;

        return new ProjectContext(
            projectId: 42,
            projectName: 'Строительная площадка',
            organizationId: 7,
            organizationName: 'Организация',
            role: $role,
            roleConfig: new ProjectRoleConfig($role, $permissions, false, true, false, false, false, 'Роль'),
            isOwner: false,
        );
    }

    private function activeSafetyModules(): AccessController
    {
        return new class extends AccessController {
            public function hasModuleAccess(int $organizationId, string $moduleSlug): bool
            {
                return $organizationId === 7 && in_array($moduleSlug, [
                    'safety-management',
                    'project-management',
                    'file-management',
                ], true);
            }
        };
    }
}

final class FixtureProblemSource implements ProjectProblemSource
{
    public function __construct(
        private readonly bool $available,
        private readonly array $items,
    ) {
    }

    public function isAvailable(ProjectContext $projectContext): bool
    {
        return $this->available && $projectContext->hasPermission('problems.view');
    }

    public function collect(Project $project, ProjectContext $projectContext): iterable
    {
        return $this->items;
    }
}
