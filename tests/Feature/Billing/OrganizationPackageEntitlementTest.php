<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\PackageAccessSource;
use App\Enums\Billing\PackageSubscriptionStatus;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationModuleActivation;
use App\Models\OrganizationPackageSubscription;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrganizationPackageEntitlementTest extends TestCase
{
    private Organization $organization;

    private OrganizationCommercialAccount $account;

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
        $this->organization = $this->createOrganization('Основная организация');
        $this->account = $this->createAccount($this->organization);
    }

    public function test_foundation_is_available_without_paid_packages(): void
    {
        $foundation = app(PackageCatalogService::class)->foundationModules();
        $this->createModules($foundation);

        $this->assertEqualsCanonicalizing(
            $foundation,
            $this->entitlements()->getEffectiveModuleSlugs($this->organization->id),
        );
    }

    public function test_active_paid_package_uses_standard_catalog(): void
    {
        $packageModules = $this->createPackageModules('estimates-norms');
        $this->createPackageSubscription('estimates-norms');

        $this->assertEqualsCanonicalizing(
            $packageModules,
            array_values(array_intersect(
                $packageModules,
                $this->entitlements()->getEffectiveModuleSlugs($this->organization->id),
            )),
        );

        $source = $this->entitlements()->getPackageModuleSources($this->organization->id)['budget-estimates'];

        $this->assertSame('paid_package', $source['access_source']);
        $this->assertSame($this->account->id, $source['commercial_account_id']);
        $this->assertArrayNotHasKey('tier', $source);
        $this->assertArrayNotHasKey('subscription_id', $source);
        $this->assertArrayNotHasKey('is_bundled_with_plan', $source);
    }

    public function test_active_trial_and_scheduled_removal_keep_access_until_their_deadlines(): void
    {
        $this->createPackageModules('estimates-norms');
        $trial = $this->createPackageSubscription(
            'estimates-norms',
            PackageSubscriptionStatus::Trialing,
            PackageAccessSource::Trial,
            null,
            now()->addDay(),
        );

        $this->assertContains('budget-estimates', $this->entitlements()->getEffectiveModuleSlugs($this->organization->id));

        $trial->update([
            'status' => PackageSubscriptionStatus::ScheduledForRemoval,
            'access_source' => PackageAccessSource::PaidPackage,
            'trial_ends_at' => null,
            'current_period_end_at' => now()->addDay(),
        ]);

        $this->assertContains('budget-estimates', $this->entitlements()->getEffectiveModuleSlugs($this->organization->id));
    }

    public function test_expired_canceled_unknown_and_foreign_packages_do_not_grant_access(): void
    {
        $this->createPackageModules('estimates-norms');
        $foreign = $this->createOrganization('Другая организация');
        $foreignAccount = $this->createAccount($foreign);

        $this->createPackageSubscription(
            'estimates-norms',
            PackageSubscriptionStatus::Expired,
            account: $this->account,
        );
        $this->createPackageSubscription(
            'unknown-package',
            PackageSubscriptionStatus::Active,
            account: $this->account,
        );
        $this->createPackageSubscription(
            'estimates-norms',
            PackageSubscriptionStatus::Active,
            account: $foreignAccount,
        );

        $this->assertNotContains('budget-estimates', $this->entitlements()->getEffectiveModuleSlugs($this->organization->id));
        $this->assertContains('budget-estimates', $this->entitlements()->getEffectiveModuleSlugs($foreign->id));
    }

    public function test_package_linked_to_another_organizations_account_does_not_grant_access(): void
    {
        $this->createPackageModules('estimates-norms');
        $foreign = $this->createOrganization('Чужая организация');
        $foreignAccount = $this->createAccount($foreign);

        OrganizationPackageSubscription::create([
            'organization_id' => $this->organization->id,
            'commercial_account_id' => $foreignAccount->id,
            'package_slug' => 'estimates-norms',
            'status' => PackageSubscriptionStatus::Active,
            'access_source' => PackageAccessSource::PaidPackage,
            'price_paid' => 12900,
            'current_period_start_at' => now(),
            'current_period_end_at' => now()->addDays(30),
        ]);

        $this->assertNotContains(
            'budget-estimates',
            $this->entitlements()->getEffectiveModuleSlugs($this->organization->id),
        );
    }

    public function test_null_period_end_is_denied_for_paid_statuses_and_allowed_only_for_corporate(): void
    {
        $this->createPackageModules('estimates-norms');
        $this->createPackageModules('machinery');
        $this->createPackageSubscription(
            'estimates-norms',
            PackageSubscriptionStatus::Grace,
            periodEnd: false,
        );
        $this->createPackageSubscription(
            'machinery',
            PackageSubscriptionStatus::Active,
            PackageAccessSource::Corporate,
            periodEnd: false,
        );

        $slugs = $this->entitlements()->getEffectiveModuleSlugs($this->organization->id);

        $this->assertNotContains('rate-management', $slugs);
        $this->assertContains('machinery-operations', $slugs);
    }

    public function test_shared_module_uses_the_source_with_longest_access(): void
    {
        $this->createModules(['budget-estimates']);
        $this->createPackageSubscription('estimates-norms', periodEnd: now()->addDays(5));
        $this->createPackageSubscription('finance-contracts', periodEnd: now()->addDays(20));

        $source = $this->entitlements()->getPackageModuleSources($this->organization->id)['budget-estimates'];

        $this->assertSame('finance-contracts', $source['package_slug']);
        $this->assertSame(0, OrganizationModuleActivation::count());
    }

    public function test_deleted_package_row_revokes_access_without_materialization(): void
    {
        $this->createPackageModules('estimates-norms');
        $package = $this->createPackageSubscription('estimates-norms');

        $this->assertContains('budget-estimates', $this->entitlements()->getEffectiveModuleSlugs($this->organization->id));
        $package->delete();
        $this->assertNotContains('budget-estimates', $this->entitlements()->getEffectiveModuleSlugs($this->organization->id));
        $this->assertSame(0, OrganizationModuleActivation::count());
    }

    public function test_indefinite_manual_activation_is_unchanged(): void
    {
        $module = $this->createModules(['budget-estimates'])[0];
        $activation = OrganizationModuleActivation::create([
            'organization_id' => $this->organization->id,
            'module_id' => $module->id,
            'is_bundled_with_plan' => false,
            'status' => 'active',
            'activated_at' => now(),
            'expires_at' => null,
            'paid_amount' => 0,
            'module_settings' => ['source' => 'manual'],
        ]);
        $activation->refresh();
        $snapshot = $activation->getRawOriginal();
        $package = $this->createPackageSubscription('estimates-norms');

        $package->delete();
        $this->entitlements()->getEffectiveModuleSlugs($this->organization->id);

        $this->assertSame($snapshot, $activation->fresh()->getRawOriginal());
    }

    public function test_package_access_has_no_materialization_api(): void
    {
        $adminService = file_get_contents(app_path('Services/Filament/ModuleAdminActionService.php'));

        $this->assertIsString($adminService);
        $this->assertFalse(method_exists(\App\Services\SubscriptionModuleSyncService::class, 'repairPackageModuleActivations'));
        $this->assertFileDoesNotExist(app_path('Console/Commands/RepairPackageModuleEntitlementsCommand.php'));
        $this->assertStringNotContainsString('package_repair', $adminService);
        $this->assertStringNotContainsString('repairPackageModuleActivations', $adminService);
    }

    private function entitlements(): OrganizationEntitlementService
    {
        return app(OrganizationEntitlementService::class);
    }

    private function createOrganization(string $name): Organization
    {
        return Organization::withoutEvents(static fn (): Organization => Organization::create([
            'name' => $name,
            'is_active' => true,
            'is_verified' => true,
        ]));
    }

    private function createAccount(Organization $organization): OrganizationCommercialAccount
    {
        return OrganizationCommercialAccount::create([
            'organization_id' => $organization->id,
            'status' => 'active',
            'offer_type' => 'packages',
            'quote_version' => 1,
            'billing_anchor_at' => now(),
            'current_period_start_at' => now(),
            'current_period_end_at' => now()->addDays(30),
            'auto_renew_enabled' => true,
        ]);
    }

    private function createPackageSubscription(
        string $packageSlug,
        PackageSubscriptionStatus $status = PackageSubscriptionStatus::Active,
        PackageAccessSource $source = PackageAccessSource::PaidPackage,
        mixed $periodEnd = null,
        mixed $trialEnd = null,
        ?OrganizationCommercialAccount $account = null,
    ): OrganizationPackageSubscription {
        $account ??= $this->account;

        return OrganizationPackageSubscription::create([
            'organization_id' => $account->organization_id,
            'commercial_account_id' => $account->id,
            'package_slug' => $packageSlug,
            'status' => $status,
            'access_source' => $source,
            'price_paid' => 1000,
            'current_period_start_at' => now(),
            'current_period_end_at' => $periodEnd === false ? null : ($periodEnd ?? now()->addDays(30)),
            'trial_started_at' => $trialEnd === null ? null : now(),
            'trial_ends_at' => $trialEnd,
        ]);
    }

    private function createPackageModules(string $packageSlug): array
    {
        $slugs = app(PackageCatalogService::class)->tierModules($packageSlug, 'standard');
        $this->createModules($slugs);

        return $slugs;
    }

    private function createModules(array $slugs): array
    {
        return array_map(
            static fn (string $slug): Module => Module::firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $slug,
                    'version' => '1.0.0',
                    'type' => 'feature',
                    'billing_model' => 'subscription',
                    'category' => 'billing-test',
                    'description' => $slug,
                    'pricing_config' => [],
                    'features' => [],
                    'permissions' => [],
                    'dependencies' => [],
                    'conflicts' => [],
                    'limits' => [],
                    'display_order' => 1,
                    'is_active' => true,
                    'is_system_module' => false,
                    'can_deactivate' => true,
                ],
            ),
            $slugs,
        );
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('organization_module_activations');
        Schema::dropIfExists('organization_package_subscriptions');
        Schema::dropIfExists('organization_commercial_accounts');
        Schema::dropIfExists('modules');
        Schema::dropIfExists('organizations');

        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('organization_commercial_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique();
            $table->string('status');
            $table->string('offer_type');
            $table->unsignedInteger('quote_version');
            $table->timestamp('billing_anchor_at')->nullable();
            $table->timestamp('current_period_start_at')->nullable();
            $table->timestamp('current_period_end_at')->nullable();
            $table->boolean('auto_renew_enabled');
            $table->timestamps();
        });

        Schema::create('modules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('version')->default('1.0.0');
            $table->string('type')->default('feature');
            $table->string('billing_model')->default('subscription');
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->json('pricing_config')->nullable();
            $table->json('features')->nullable();
            $table->json('permissions')->nullable();
            $table->json('dependencies')->nullable();
            $table->json('conflicts')->nullable();
            $table->json('limits')->nullable();
            $table->integer('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system_module')->default(false);
            $table->boolean('can_deactivate')->default(true);
            $table->timestamps();
        });

        Schema::create('organization_module_activations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('module_id');
            $table->foreignId('subscription_id')->nullable();
            $table->boolean('is_bundled_with_plan')->default(false);
            $table->string('status')->default('active');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->decimal('paid_amount', 10, 2)->default(0);
            $table->json('module_settings')->nullable();
            $table->timestamps();
        });

        Schema::create('organization_package_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('commercial_account_id');
            $table->string('package_slug', 100);
            $table->string('status');
            $table->string('access_source');
            $table->decimal('price_paid', 10, 2)->default(0);
            $table->timestamp('current_period_start_at')->nullable();
            $table->timestamp('current_period_end_at')->nullable();
            $table->timestamp('trial_started_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('cancel_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'package_slug']);
        });
    }
}
