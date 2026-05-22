<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Mobile\MobileDashboardService;
use DomainException;
use Mockery\MockInterface;
use Tests\TestCase;

final class MobileDashboardTest extends TestCase
{
    public function test_mobile_dashboard_returns_new_contract_for_available_modules(): void
    {
        $this->mockDashboardPermissions($this->allPermissions());

        $widgets = $this->service()->build($this->user())['widgets'];

        $this->assertSame([
            'project_overview',
            'site_requests',
            'site_request_approvals',
            'warehouse',
            'schedule',
            'ai_assistant',
            'construction_journal',
            'quality_control',
            'safety_management',
            'machinery_operations',
            'production_labor',
            'workforce_management',
            'handover_acceptance',
            'procurement',
        ], array_column($widgets, 'slug'));

        foreach ($widgets as $widget) {
            $this->assertSame([
                'slug',
                'title',
                'status',
                'primary_metric',
                'secondary_metric',
                'route',
                'updated_at',
            ], array_keys($widget));
            $this->assertIsString($widget['slug']);
            $this->assertIsString($widget['title']);
            $this->assertContains($widget['status'], ['ok', 'active', 'attention', 'critical']);
            $this->assertIsArray($widget['primary_metric']);
            $this->assertIsArray($widget['secondary_metric']);
            $this->assertIsString($widget['route']);
            $this->assertIsString($widget['updated_at']);
        }
    }

    public function test_mobile_dashboard_does_not_return_old_widget_fields(): void
    {
        $this->mockDashboardPermissions($this->allPermissions());

        $widgets = $this->service()->build($this->user())['widgets'];

        foreach ($widgets as $widget) {
            foreach (['type', 'order', 'description', 'badge', 'payload'] as $oldField) {
                $this->assertArrayNotHasKey($oldField, $widget);
            }
        }
    }

    public function test_mobile_dashboard_respects_module_access(): void
    {
        $this->mockDashboardPermissions(
            [
                'quality-control' => ['quality-control.view'],
                'safety-management' => ['safety-management.view'],
            ],
            accessibleSlugs: ['quality-control'],
        );

        $slugs = array_column($this->service()->build($this->user())['widgets'], 'slug');

        $this->assertContains('quality_control', $slugs);
        $this->assertNotContains('safety_management', $slugs);
    }

    public function test_mobile_dashboard_requires_current_organization(): void
    {
        $this->expectException(DomainException::class);

        $user = new User();
        $user->current_organization_id = null;

        $this->service()->build($user);
    }

    /**
     * @param array<string, list<string>> $permissions
     * @param list<string>|null $accessibleSlugs
     */
    private function mockDashboardPermissions(array $permissions, ?array $accessibleSlugs = null): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock) use ($permissions): void {
            $mock->shouldReceive('getUserPermissionsStructured')->andReturn([
                'system' => [],
                'modules' => $permissions,
            ]);
            $mock->shouldReceive('getUserRoles')->andReturn(collect([
                (object) ['role_slug' => 'foreman'],
            ]));
        });

        $accessible = $accessibleSlugs ?? array_keys($permissions);

        $this->mock(AccessController::class, function (MockInterface $mock) use ($accessible): void {
            $mock->shouldReceive('hasModuleAccess')->andReturnUsing(
                static fn (int $organizationId, string $moduleSlug): bool => in_array($moduleSlug, $accessible, true),
            );
        });
    }

    /**
     * @return array<string, list<string>>
     */
    private function allPermissions(): array
    {
        return [
            'project-management' => ['projects.view'],
            'site-requests' => ['site_requests.view', 'site_requests.approve'],
            'basic-warehouse' => ['warehouse.view'],
            'schedule-management' => ['schedule.view'],
            'ai-assistant' => ['ai-assistant.view'],
            'budget-estimates' => ['construction-journal.view'],
            'quality-control' => ['quality-control.view'],
            'safety-management' => ['safety-management.view'],
            'machinery-operations' => ['machinery-operations.view'],
            'production-labor' => ['production-labor.view'],
            'workforce-management' => ['workforce.view'],
            'handover-acceptance' => ['handover-acceptance.view'],
            'procurement' => ['procurement.view'],
        ];
    }

    private function service(): MobileDashboardService
    {
        return app(MobileDashboardService::class);
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 321;
        $user->current_organization_id = 123;

        return $user;
    }
}
