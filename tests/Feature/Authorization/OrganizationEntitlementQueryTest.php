<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\Module;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class OrganizationEntitlementQueryTest extends TestCase
{
    public function test_matching_module_reads_preserve_access_and_fresh_revocation(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug'));
        $service = app(OrganizationEntitlementService::class);
        $free = $service->getEffectiveModuleSlugs($fixture->foreignOrganization->id);
        $paid = array_values(array_diff($service->getEffectiveModuleSlugs($fixture->organization->id), $free));
        self::assertNotEmpty($paid);
        self::assertSame([$paid[0]], $service->getEffectiveModuleSlugsMatching($fixture->organization->id, $paid[0]));
        self::assertSame([], $service->getEffectiveModuleSlugsMatching($fixture->foreignOrganization->id, $paid[0]));
        self::assertSame([], $service->getEffectiveModuleSlugsMatching($fixture->organization->id, 'unknown-candidate-module'));

        Module::query()->where('slug', $paid[0])->update(['is_active' => false]);
        self::assertSame([], $service->getEffectiveModuleSlugsMatching($fixture->organization->id, $paid[0]));
        Module::query()->where('slug', $paid[0])->update(['is_active' => true]);
        self::assertSame([$paid[0]], $service->getEffectiveModuleSlugsMatching($fixture->organization->id, $paid[0]));
        OrganizationPackageSubscription::query()->where('organization_id', $fixture->organization->id)->update(['status' => 'canceled']);
        self::assertSame([], $service->getEffectiveModuleSlugsMatching($fixture->organization->id, $paid[0]));
    }

    public function test_non_bindable_candidates_keep_the_legacy_database_reads(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $service = app(OrganizationEntitlementService::class);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            if (preg_match('/^select\b.*from "modules"/is', $query->sql)) {
                $queries[] = $query;
            }
        });
        $candidates = ["binary\0module", "invalid\xffmodule", str_repeat('x', 1025)];
        foreach ($candidates as $candidate) {
            self::assertSame([], $service->getEffectiveModuleSlugsMatching($fixture->organization->id, $candidate));
        }

        self::assertCount(count($candidates), $queries);
        foreach ($queries as $query) {
            foreach ($candidates as $candidate) {
                self::assertNotContains($candidate, $query->bindings);
            }
        }
    }

    public function test_scoped_catalog_keeps_new_entitlement_reads_fresh(): void
    {
        $catalog = app(PackageCatalogService::class);
        $fixture = AssistantRealAuthorizationFixture::create(array_column($catalog->allPackages(), 'slug'));
        $service = app(OrganizationEntitlementService::class);
        $free = $service->getEffectiveModuleSlugs($fixture->foreignOrganization->id);
        $paid = array_values(array_diff($service->getEffectiveModuleSlugs($fixture->organization->id), $free));
        self::assertNotEmpty($paid);
        Module::query()->where('slug', $paid[0])->update(['is_active' => false]);

        $next = app(OrganizationEntitlementService::class);
        self::assertNotSame($service, $next);
        self::assertSame($catalog, app(PackageCatalogService::class));
        self::assertNotContains($paid[0], $next->getEffectiveModuleSlugs($fixture->organization->id));
        self::assertEqualsCanonicalizing($free, $next->getEffectiveModuleSlugs($fixture->foreignOrganization->id));

        Module::query()->where('slug', $paid[0])->update(['is_active' => true]);
        OrganizationPackageSubscription::query()->where('organization_id', $fixture->organization->id)->update(['status' => 'canceled']);
        self::assertEqualsCanonicalizing($free, app(OrganizationEntitlementService::class)->getEffectiveModuleSlugs($fixture->organization->id));
    }

    public function test_module_rows_are_loaded_once_and_inactive_foundation_is_excluded(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $foundation = app(PackageCatalogService::class)->foundationModules();
        self::assertNotEmpty($foundation);
        Module::query()->where('slug', $foundation[0])->update(['is_active' => false]);
        $reads = 0;
        DB::listen(static function ($query) use (&$reads): void {
            if (preg_match('/from "(?:modules|organization_package_subscriptions)"/i', $query->sql)) {
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

    public function test_module_reads_preserve_current_subscription_windows(): void
    {
        $this->freezeTime();
        $catalog = app(PackageCatalogService::class);
        $fixture = AssistantRealAuthorizationFixture::create(array_column($catalog->allPackages(), 'slug'));
        $service = app(OrganizationEntitlementService::class);
        $free = $service->getEffectiveModuleSlugs($fixture->foreignOrganization->id);
        $paid = array_values(array_diff($service->getEffectiveModuleSlugs($fixture->organization->id), $free));
        self::assertNotEmpty($paid);
        $account = OrganizationCommercialAccount::query()->findOrFail($fixture->subscription->commercial_account_id);
        $subscriptions = OrganizationPackageSubscription::query()->where('organization_id', $fixture->organization->id);
        $cases = [
            ['active paid', 'active', 'paid_package', now()->addHour(), null, 'active', null, false, true],
            ['paid without period', 'active', 'paid_package', null, null, 'active', null, false, false],
            ['unlimited corporate', 'active', 'corporate', null, null, 'active', null, false, true],
            ['expired corporate', 'active', 'corporate', now()->subHour(), null, 'active', null, false, false],
            ['scheduled removal', 'scheduled_for_removal', 'paid_package', now()->addHour(), null, 'active', null, false, true],
            ['current trial', 'trialing', 'paid_package', null, now()->addMinute(), 'active', null, false, true],
            ['expired trial', 'trialing', 'paid_package', null, now(), 'active', null, false, false],
            ['canceled', 'canceled', 'paid_package', now()->addHour(), null, 'active', null, false, false],
            ['current grace', 'grace', 'paid_package', now()->subHour(), null, 'grace', now()->addMinute(), false, true],
            ['expired grace', 'grace', 'paid_package', now()->subHour(), null, 'grace', now(), false, false],
            ['renewal processing', 'active', 'paid_package', now()->subMinute(), null, 'active', null, true, true],
            ['renewal without payment', 'active', 'paid_package', now()->subMinute(), null, 'active', null, false, false],
        ];
        foreach ($cases as [$label, $status, $source, $periodEnd, $trialEnd, $accountStatus, $graceEnd, $renew, $expected]) {
            $account->forceFill([
                'status' => $accountStatus, 'current_period_end_at' => $periodEnd, 'billing_anchor_at' => $periodEnd,
                'grace_ends_at' => $graceEnd, 'auto_renew_enabled' => $renew,
                'saved_payment_method_active' => $renew, 'saved_payment_method_id' => $renew ? 'pm_fixture' : null,
            ])->save();
            (clone $subscriptions)->update([
                'status' => $status, 'access_source' => $source,
                'current_period_end_at' => $periodEnd, 'trial_ends_at' => $trialEnd,
            ]);
            self::assertSame($expected, in_array($paid[0], $service->getEffectiveModuleSlugs($fixture->organization->id), true), $label);
            self::assertSame($expected, isset($service->getPackageModuleSources($fixture->organization->id)[$paid[0]]), $label);
        }
    }

    public function test_retired_package_alias_and_unknown_package_preserve_module_access(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        config(['commercial_offers.retired_entry_packages' => ['legacy-entitlement-fixture']]);
        $service = app(OrganizationEntitlementService::class);
        $before = $service->getEffectiveModuleSlugs($fixture->organization->id);
        $sources = $service->getPackageModuleSources($fixture->organization->id);
        $fixture->subscription->update(['package_slug' => 'legacy-entitlement-fixture']);
        self::assertEqualsCanonicalizing($before, $service->getEffectiveModuleSlugs($fixture->organization->id));
        self::assertEquals($sources, $service->getPackageModuleSources($fixture->organization->id));
        $fixture->subscription->update(['package_slug' => 'unknown-entitlement-fixture']);
        self::assertSame([], $service->getPackageModuleSources($fixture->organization->id));
        self::assertEqualsCanonicalizing($service->getEffectiveModuleSlugs($fixture->foreignOrganization->id), $service->getEffectiveModuleSlugs($fixture->organization->id));
    }
}
