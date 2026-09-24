<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\Services\ModulePermissionChecker;
use App\Models\Module;
use App\Services\ActReport\ActReportAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('postgresql')]
final class ActReportApprovalPermissionCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
    }

    public function test_approval_permission_is_assignable_after_module_catalog_update(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(config_path('ModuleList/addons/act-reporting.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertContains(ActReportAccessService::PERMISSION_APPROVE, $manifest['permissions']);

        $module = Module::query()->create([
            'name' => 'Акты',
            'slug' => 'act-reporting',
            'version' => '1.0.0',
            'type' => 'core',
            'billing_model' => 'free',
            'permissions' => ['act_reports.view', 'act_reports.edit'],
            'is_active' => true,
        ]);
        $migration = require database_path('migrations/2026_09_24_210000_add_act_report_approve_permission_to_module.php');
        $migration->up();
        $migration->up();

        $permissions = app(ModulePermissionChecker::class)->getModulePermissions('act-reporting');
        self::assertSame(1, count(array_filter(
            $permissions,
            static fn (string $permission): bool => $permission === ActReportAccessService::PERMISSION_APPROVE,
        )));
        self::assertContains('act_reports.view', $permissions);
        self::assertContains('act_reports.edit', $permissions);
        self::assertTrue(app(ModulePermissionChecker::class)->moduleHasPermission(
            'act-reporting',
            ActReportAccessService::PERMISSION_APPROVE,
        ));

        $labels = require lang_path('ru/permissions.php');
        self::assertNotEmpty($labels['values'][ActReportAccessService::PERMISSION_APPROVE] ?? null);

        $migration->down();
        self::assertNotContains(
            ActReportAccessService::PERMISSION_APPROVE,
            $module->fresh()->permissions,
        );
    }
}
