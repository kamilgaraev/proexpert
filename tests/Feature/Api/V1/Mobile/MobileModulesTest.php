<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Mobile\MobileModulesService;
use DomainException;
use Mockery\MockInterface;
use Tests\TestCase;

final class MobileModulesTest extends TestCase
{
    public function test_mobile_modules_catalog_contains_supported_field_modules(): void
    {
        $expected = [
            'construction-journal' => ['icon' => 'journal', 'route' => 'construction_journal'],
            'quality-control' => ['icon' => 'quality', 'route' => 'quality-control'],
            'safety-management' => ['icon' => 'shield-check', 'route' => 'safety-management'],
            'machinery-operations' => ['icon' => 'machinery', 'route' => 'machinery-operations'],
            'production-labor' => ['icon' => 'engineer', 'route' => 'production-labor'],
            'workforce-management' => ['icon' => 'workforce', 'route' => 'workforce-management'],
            'handover-acceptance' => ['icon' => 'handover', 'route' => 'handover-acceptance'],
            'workflow-management' => ['icon' => 'hub', 'route' => 'workflow-management'],
            'time-tracking' => ['icon' => 'timer', 'route' => 'time-tracking'],
            'budget-estimates' => ['icon' => 'calculate', 'route' => 'budget-estimates'],
            'procurement' => ['icon' => 'procurement', 'route' => 'procurement'],
            'contract-management' => ['icon' => 'contract', 'route' => 'contract-management'],
            'change-management' => ['icon' => 'change', 'route' => 'change-management'],
            'executive-documentation' => ['icon' => 'documents', 'route' => 'executive-documentation'],
            'project-management' => ['icon' => 'project', 'route' => 'project-management'],
            'catalog-management' => ['icon' => 'catalog', 'route' => 'catalog-management'],
            'brigades' => ['icon' => 'brigades', 'route' => 'brigades'],
            'video-monitoring' => ['icon' => 'video', 'route' => 'video-monitoring'],
        ];

        $this->mockModulePermissions(array_keys($expected));

        $modules = collect($this->service()->build($this->user())['modules'])->keyBy('slug');

        foreach ($expected as $slug => $contract) {
            $this->assertTrue($modules->has($slug), "Mobile catalog does not contain {$slug}");
            $this->assertTrue($modules[$slug]['supported_on_mobile']);
            $this->assertSame($contract['icon'], $modules[$slug]['icon']);
            $this->assertSame($contract['route'], $modules[$slug]['route']);
        }
    }

    public function test_mobile_modules_catalog_returns_translated_titles(): void
    {
        $this->mockModulePermissions(['budget-estimates']);

        $module = collect($this->service()->build($this->user())['modules'])->firstWhere(
            'slug',
            'construction-journal',
        );

        $this->assertSame('Журнал работ', $module['title']);
        $this->assertSame(
            'Ежедневные записи, объемы работ, согласование и экспорт КС-6 по объекту.',
            $module['description'],
        );
    }

    public function test_mobile_modules_catalog_maps_construction_journal_to_budget_access(): void
    {
        $this->mockModulePermissions(['budget-estimates']);

        $slugs = collect($this->service()->build($this->user())['modules'])->pluck('slug')->all();

        $this->assertContains('construction-journal', $slugs);
        $this->assertContains('budget-estimates', $slugs);
    }

    public function test_mobile_modules_catalog_respects_permissions_and_access(): void
    {
        $this->mockModulePermissions(
            ['quality-control', 'safety-management'],
            accessibleSlugs: ['quality-control'],
        );

        $slugs = collect($this->service()->build($this->user())['modules'])->pluck('slug')->all();

        $this->assertContains('quality-control', $slugs);
        $this->assertNotContains('safety-management', $slugs);
        $this->assertNotContains('basic-warehouse', $slugs);
    }

    public function test_mobile_modules_catalog_requires_current_organization(): void
    {
        $this->expectException(DomainException::class);

        $user = new User();
        $user->current_organization_id = null;

        $this->service()->build($user);
    }

    /**
     * @param list<string> $permissionSlugs
     * @param list<string>|null $accessibleSlugs
     */
    private function mockModulePermissions(array $permissionSlugs, ?array $accessibleSlugs = null): void
    {
        $permissions = [];

        foreach ($permissionSlugs as $slug) {
            $permissions[$slug] = ['view'];
        }

        $this->mock(AuthorizationService::class, function (MockInterface $mock) use ($permissions): void {
            $mock->shouldReceive('getUserPermissionsStructured')->andReturn([
                'system' => [],
                'modules' => $permissions,
            ]);
        });

        $accessible = $accessibleSlugs ?? $permissionSlugs;

        $this->mock(AccessController::class, function (MockInterface $mock) use ($accessible): void {
            $mock->shouldReceive('hasModuleAccess')->andReturnUsing(
                static fn (int $organizationId, string $moduleSlug): bool => in_array($moduleSlug, $accessible, true),
            );
        });
    }

    private function service(): MobileModulesService
    {
        return app(MobileModulesService::class);
    }

    private function user(): User
    {
        $user = new User();
        $user->current_organization_id = 123;

        return $user;
    }
}
