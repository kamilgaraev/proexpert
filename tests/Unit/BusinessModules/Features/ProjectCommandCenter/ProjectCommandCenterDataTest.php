<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Features\ProjectCommandCenter;

use App\BusinessModules\Features\ProjectCommandCenter\DTO\ProjectCommandCenterData;
use App\Domain\Project\ValueObjects\ProjectContext;
use App\Domain\Project\ValueObjects\ProjectRoleConfig;
use App\Enums\ProjectOrganizationRole;
use App\Models\Project;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ProjectCommandCenterDataTest extends TestCase
{
    public function test_it_provides_the_stable_empty_command_center_contract(): void
    {
        $project = new Project([
            'name' => 'Строительная площадка',
            'status' => 'active',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);
        $project->setAttribute('id', 42);

        $data = ProjectCommandCenterData::empty(
            project: $project,
            projectContext: $this->projectContext(
                role: ProjectOrganizationRole::OWNER,
                canViewFinances: true,
            ),
            period: 'project',
            dateFrom: null,
            dateTo: null,
            generatedAt: new DateTimeImmutable('2026-07-21T12:00:00+03:00'),
        );

        self::assertSame([
            'project',
            'period',
            'generated_at',
            'problems',
            'finance',
            'delivery',
            'analytics',
        ], array_keys($data->toArray()));
        self::assertSame(42, $data->toArray()['project']['id']);
        self::assertSame('project', $data->toArray()['period']['preset']);
        self::assertSame([], $data->toArray()['problems']);
        self::assertSame([], $data->toArray()['finance']);
        self::assertSame([], $data->toArray()['delivery']);
        self::assertSame([], $data->toArray()['analytics']);
    }

    public function test_it_marks_finance_as_unavailable_without_financial_access(): void
    {
        $project = new Project(['name' => 'Строительная площадка']);
        $project->setAttribute('id', 42);

        $data = ProjectCommandCenterData::empty(
            project: $project,
            projectContext: $this->projectContext(
                role: ProjectOrganizationRole::SUBCONTRACTOR,
                canViewFinances: false,
            ),
            period: 'project',
            dateFrom: null,
            dateTo: null,
            generatedAt: new DateTimeImmutable('2026-07-21T12:00:00+03:00'),
        );

        self::assertSame(['available' => false], $data->toArray()['finance']);
    }

    public function test_it_allows_own_finances_permission_without_global_finance_access(): void
    {
        $project = new Project(['name' => 'Строительная площадка']);
        $project->setAttribute('id', 42);

        $data = ProjectCommandCenterData::empty(
            project: $project,
            projectContext: $this->projectContext(
                role: ProjectOrganizationRole::SUBCONTRACTOR,
                canViewFinances: false,
                permissions: ['view_own_finances'],
            ),
            period: 'project',
            dateFrom: null,
            dateTo: null,
            generatedAt: new DateTimeImmutable('2026-07-21T12:00:00+03:00'),
        );

        self::assertSame([], $data->toArray()['finance']);
    }

    public function test_it_preserves_the_truthful_analytics_contract(): void
    {
        $project = new Project(['name' => 'РЎС‚СЂРѕРёС‚РµР»СЊРЅР°СЏ РїР»РѕС‰Р°РґРєР°']);
        $project->setAttribute('id', 42);

        $data = ProjectCommandCenterData::empty(
            project: $project,
            projectContext: $this->projectContext(ProjectOrganizationRole::OWNER, true),
            period: 'project',
            dateFrom: null,
            dateTo: null,
            generatedAt: new DateTimeImmutable('2026-07-21T12:00:00+03:00'),
        )->withAnalytics([
            'risk_trend' => [
                'available' => false,
                'reason_key' => 'project_command_center.analytics.risk_trend_history_unavailable',
            ],
            'cost_outlook' => [
                'available' => true,
                'title_key' => 'project_command_center.analytics.cost_outlook',
                'labels' => ['actual_cost', 'forecast_remaining_cost'],
                'series' => ['amount' => [400.0, 800.0]],
            ],
        ]);

        self::assertArrayHasKey('cost_outlook', $data->toArray()['analytics']);
        self::assertArrayNotHasKey('cost_breakdown', $data->toArray()['analytics']);
        self::assertFalse($data->toArray()['analytics']['risk_trend']['available']);
    }

    private function projectContext(
        ProjectOrganizationRole $role,
        bool $canViewFinances,
        array $permissions = [],
    ): ProjectContext {
        return new ProjectContext(
            projectId: 42,
            projectName: 'Строительная площадка',
            organizationId: 7,
            organizationName: 'Организация',
            role: $role,
            roleConfig: new ProjectRoleConfig(
                role: $role,
                permissions: $permissions,
                canManageContracts: false,
                canViewFinances: $canViewFinances,
                canManageWorks: false,
                canManageWarehouse: false,
                canInviteParticipants: false,
                displayLabel: 'Роль',
            ),
            isOwner: false,
        );
    }
}
