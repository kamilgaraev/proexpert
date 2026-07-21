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

    private function projectContext(
        ProjectOrganizationRole $role,
        bool $canViewFinances,
    ): ProjectContext {
        return new ProjectContext(
            projectId: 42,
            projectName: 'Строительная площадка',
            organizationId: 7,
            organizationName: 'Организация',
            role: $role,
            roleConfig: new ProjectRoleConfig(
                role: $role,
                permissions: [],
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
