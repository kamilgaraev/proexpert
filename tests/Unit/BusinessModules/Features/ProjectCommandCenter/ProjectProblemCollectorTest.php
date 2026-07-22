<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Features\ProjectCommandCenter;

use App\BusinessModules\Features\ProjectCommandCenter\DTO\ProjectProblemItem;
use App\BusinessModules\Features\ProjectCommandCenter\Services\ProjectProblemCollector;
use App\BusinessModules\Features\ProjectCommandCenter\Services\ProjectProblemSource;
use App\BusinessModules\Features\ProjectCommandCenter\Services\Sources\SafetyViolationProblemSource;
use App\BusinessModules\Features\ProjectCommandCenter\Services\Sources\OverdueSiteRequestProblemSource;
use App\BusinessModules\Features\ProjectCommandCenter\Services\Sources\ProcurementProblemSource;
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
                $this->problem('quality-defect-4', 'warning', 'quality', 'quality', null, null, '2026-07-17'),
            ]),
        ]);

        $result = $collector->collect($this->project(), $this->context(), new DateTimeImmutable('2026-07-21T12:00:00+03:00'));

        self::assertSame(['safety-violation-3', 'schedule-task-7', 'completed-work-5', 'material-8', 'quality-defect-4'], array_column($result['items'], 'id'));
        self::assertSame(['total' => 5, 'critical' => 1, 'risk' => 4, 'attention' => 0], $result['summary']);
        self::assertSame('/safety-management', $result['items'][0]['action']['route']);
        self::assertSame(['project_id' => 42], $result['items'][0]['action']['query']);
        self::assertArrayNotHasKey('url', $result['items'][0]['action']);
        self::assertSame('/projects/42/schedules', $result['items'][1]['action']['route']);
        self::assertSame(['project_id' => 42], $result['items'][1]['action']['query']);
        self::assertSame('/workflow/completed-works', $result['items'][2]['action']['route']);
        self::assertSame(['project_id' => 42], $result['items'][2]['action']['query']);
        self::assertNull($result['items'][3]['action']);
        self::assertNull($result['items'][4]['action']);
        self::assertStringNotContainsString('/materials', json_encode($result['items'], JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('/quality-control/defects', json_encode($result['items'], JSON_THROW_ON_ERROR));
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
        self::assertSame('/safety-management', $result['items'][0]['action']['route']);
        self::assertSame(['project_id' => 42], $result['items'][0]['action']['query']);
        self::assertStringNotContainsString('/safety/violations', json_encode($result['items'][0]['action'], JSON_THROW_ON_ERROR));
        self::assertSame(['project_id' => 42, 'organization_id' => 7], $captured);
        self::assertSame('Нарушение не устранено в срок.', $result['items'][0]['description']);
        self::assertStringNotContainsString('project_command_center.', $result['items'][0]['description']);
    }

    public function test_it_translates_command_center_problem_description_keys_for_user_output(): void
    {
        $item = ProjectProblemItem::fromWorkflowSurface(
            id: 'schedule-task-7',
            module: 'schedule',
            title: 'Монтаж перекрытия',
            surface: [
                'problem_flags' => [[
                    'severity' => 'warning',
                    'message' => 'project_command_center.problems.schedule_task_overdue',
                ]],
            ],
            impactTypes: ['schedule'],
            amount: null,
            dueAt: new DateTimeImmutable('2026-07-20T00:00:00+03:00'),
            detectedAt: new DateTimeImmutable('2026-07-19T00:00:00+03:00'),
            actionModule: 'schedule',
        );

        self::assertNotNull($item);
        self::assertSame('Срок выполнения задачи по графику просрочен.', $item->description);
        self::assertStringNotContainsString('project_command_center.', $item->description);
    }

    public function test_command_center_problem_description_translations_are_human_readable(): void
    {
        $descriptions = [
            'project_command_center.problems.schedule_task_overdue' => 'Срок выполнения задачи по графику просрочен.',
            'project_command_center.problems.completed_work_pending' => 'Выполненная работа ожидает проверки.',
            'project_command_center.problems.quality_defect_critical' => 'Критический дефект качества требует устранения.',
            'safety_management.problem_flags.violation_overdue' => 'Срок устранения нарушения просрочен.',
        ];

        foreach ($descriptions as $key => $expectedDescription) {
            $description = trans_message($key);

            self::assertSame($expectedDescription, $description);
            self::assertStringNotContainsString('project_command_center.', $description);
        }
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

    public function test_it_includes_overdue_site_requests_and_project_procurement_issues_in_the_problem_queue(): void
    {
        $siteRequestSource = new OverdueSiteRequestProblemSource(
            $this->activeProjectModules(['site-requests']),
            static fn (): array => [[
                'id' => 11,
                'title' => 'Бетон М300',
                'required_date' => '2026-07-14',
                'created_at' => '2026-07-10T10:00:00+03:00',
            ]],
            static fn (): string => 'Срок исполнения заявки с объекта просрочен.',
        );
        $captured = [];
        $procurementSource = new ProcurementProblemSource(
            $this->activeProjectModules(['procurement']),
            static function (ProjectContext $context) use (&$captured): array {
                $captured = [$context->organizationId, $context->projectId];

                return [[
                    'id' => 'pr-pending-12',
                    'severity' => 'warning',
                    'title' => 'Закупочная заявка ожидает рассмотрения',
                    'description' => 'Нужно принять решение по закупке.',
                    'entity_href' => '/procurement/purchase-requests/12',
                    'created_at' => '2026-07-15T10:00:00+03:00',
                ]];
            },
        );

        $result = (new ProjectProblemCollector([$siteRequestSource, $procurementSource]))->collect(
            $this->project(),
            $this->context(['site_requests.view', 'procurement.view']),
            new DateTimeImmutable('2026-07-21T12:00:00+03:00'),
        );

        self::assertSame(['site-request-11', 'procurement-pr-pending-12'], array_column($result['items'], 'id'));
        self::assertSame(['total' => 2, 'critical' => 0, 'risk' => 2, 'attention' => 0], $result['summary']);
        self::assertSame('/site-requests/11', $result['items'][0]['action']['route']);
        self::assertSame('/procurement/purchase-requests/12', $result['items'][1]['action']['route']);
        self::assertSame(['project_id' => 42], $result['items'][0]['action']['query']);
        self::assertSame(['project_id' => 42], $result['items'][1]['action']['query']);
        self::assertSame([7, 42], $captured);
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

    /** @param list<string> $modules */
    private function activeProjectModules(array $modules): AccessController
    {
        return new class ($modules) extends AccessController {
            /** @param list<string> $modules */
            public function __construct(private readonly array $modules)
            {
            }

            public function hasModuleAccess(int $organizationId, string $moduleSlug): bool
            {
                return $organizationId === 7 && in_array($moduleSlug, $this->modules, true);
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
