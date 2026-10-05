<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\Module;
use App\Models\OrganizationPackageSubscription;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class OrganizationEntitlementQueryTest extends TestCase
{
    public function test_module_rows_are_loaded_once_and_inactive_foundation_is_excluded(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $foundation = app(PackageCatalogService::class)->foundationModules();
        self::assertNotEmpty($foundation);
        Module::query()->where('slug', $foundation[0])->update(['is_active' => false]);
        $reads = 0;
        DB::listen(static function ($query) use (&$reads): void {
            if (preg_match('/from "modules"/i', $query->sql)) {
                $reads++;
            }
        });
        $modules = app(OrganizationEntitlementService::class)->getEffectiveModules($fixture->organization->id);
        self::assertSame(1, $reads);
        self::assertNotContains($foundation[0], $modules->pluck('slug')->all());
        self::assertTrue($modules->every(static fn (Module $module): bool => $module->is_active));
    }

    public function test_paid_modules_are_scoped_and_next_read_observes_revocation(): void
    {
        $packages = array_values(array_unique(['working-entry', ...array_column(app(PackageCatalogService::class)->allPackages(), 'slug')]));
        $fixture = AssistantRealAuthorizationFixture::create($packages);
        $service = app(OrganizationEntitlementService::class);
        $free = $service->getEffectiveModuleSlugs($fixture->foreignOrganization->id);
        $paid = $service->getEffectiveModuleSlugs($fixture->organization->id);
        self::assertNotEmpty(array_diff($paid, $free));
        OrganizationPackageSubscription::query()->where('organization_id', $fixture->organization->id)->update(['status' => 'canceled']);
        self::assertEqualsCanonicalizing($free, $service->getEffectiveModuleSlugs($fixture->organization->id));
        self::assertEqualsCanonicalizing($free, $service->getEffectiveModuleSlugs($fixture->foreignOrganization->id));
    }
}
