<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing;

use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Services\Landing\ModulesOverviewService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ModulesOverviewControllerTest extends TestCase
{
    public function refreshDatabase(): void {}

    public function test_runtime_overview_resolves_new_package_schema_and_enums(): void
    {
        $this->createSchema();
        $organization = Organization::withoutEvents(static fn (): Organization => Organization::create([
            'name' => 'Overview organization',
            'is_active' => true,
            'is_verified' => true,
        ]));
        $account = OrganizationCommercialAccount::create([
            'organization_id' => $organization->id,
            'status' => 'active',
            'offer_type' => 'packages',
            'quote_version' => 1,
            'current_period_start_at' => now(),
            'current_period_end_at' => now()->addDays(30),
            'auto_renew_enabled' => true,
        ]);
        OrganizationPackageSubscription::create([
            'organization_id' => $organization->id,
            'commercial_account_id' => $account->id,
            'package_slug' => 'machinery',
            'status' => 'active',
            'access_source' => 'paid_package',
            'price_paid' => 7900,
            'current_period_start_at' => now(),
            'current_period_end_at' => now()->addDays(30),
        ]);
        Module::create([
            'name' => 'Техника',
            'slug' => 'machinery-operations',
            'version' => '1.0.0',
            'type' => 'feature',
            'billing_model' => 'subscription',
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
        ]);

        $overview = app(ModulesOverviewService::class)->build($organization->id);

        $this->assertSame(10, $overview['summary']['total_solutions_count']);
        $this->assertSame(1, $overview['summary']['active_solutions_count']);
        $this->assertSame('7900.00', $overview['summary']['monthly_total']);
        $machinery = collect($overview['solutions'])->firstWhere('slug', 'machinery');
        $this->assertIsArray($machinery);
        $this->assertTrue($machinery['is_active']);
        $this->assertSame('paid_package', $machinery['access_source']);
        $this->assertArrayNotHasKey('tier', $machinery);
        $module = collect($overview['advanced_modules'])->firstWhere('slug', 'machinery-operations');
        $this->assertIsArray($module);
        $this->assertSame('packaged', $module['classification']);
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
        Schema::create('modules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('version');
            $table->string('type');
            $table->string('billing_model');
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->json('pricing_config')->nullable();
            $table->json('features')->nullable();
            $table->json('permissions')->nullable();
            $table->json('dependencies')->nullable();
            $table->json('conflicts')->nullable();
            $table->json('limits')->nullable();
            $table->string('icon')->nullable();
            $table->integer('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system_module')->default(false);
            $table->boolean('can_deactivate')->default(true);
            $table->string('development_status')->nullable();
            $table->timestamps();
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
        Schema::create('organization_package_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('commercial_account_id');
            $table->string('package_slug');
            $table->string('status');
            $table->string('access_source');
            $table->decimal('price_paid', 10, 2);
            $table->timestamp('current_period_start_at')->nullable();
            $table->timestamp('current_period_end_at')->nullable();
            $table->timestamp('trial_started_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('cancel_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();
        });
        Schema::create('organization_module_activations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('module_id');
            $table->string('status');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }
}
