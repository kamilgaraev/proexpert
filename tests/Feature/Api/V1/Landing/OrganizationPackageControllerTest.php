<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing;

use App\Interfaces\Billing\BalanceServiceInterface;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationBalance;
use App\Models\OrganizationModuleActivation;
use App\Models\OrganizationPackageSubscription;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class OrganizationPackageControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private Organization $organization;

    private User $user;

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
        $this->createSchema();

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'is_active' => true,
            'is_verified' => true,
        ]);

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'package-test@example.com',
            'password' => 'password',
            'is_active' => true,
            'current_organization_id' => $this->organization->id,
        ]);

        $this->organization->users()->attach($this->user->id, [
            'is_owner' => true,
            'is_active' => true,
        ]);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('organization_module_activations');
        Schema::dropIfExists('organization_package_subscriptions');
        Schema::dropIfExists('organization_balances');
        Schema::dropIfExists('modules');
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('users');
        Schema::dropIfExists('organizations');

        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->foreignId('current_organization_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('organization_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('user_id');
            $table->boolean('is_owner')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('modules', function (Blueprint $table) {
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
            $table->string('class_name')->nullable();
            $table->string('config_file')->nullable();
            $table->string('icon')->nullable();
            $table->integer('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system_module')->default(false);
            $table->boolean('can_deactivate')->default(true);
            $table->string('development_status')->nullable();
            $table->timestamps();
        });

        Schema::create('organization_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->integer('balance')->default(0);
            $table->integer('reserved_balance')->default(0);
            $table->string('currency', 3)->default('RUB');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('organization_module_activations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('module_id');
            $table->foreignId('subscription_id')->nullable();
            $table->boolean('is_bundled_with_plan')->default(false);
            $table->string('status')->default('active');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->decimal('paid_amount', 10, 2)->default(0);
            $table->json('payment_details')->nullable();
            $table->timestamp('next_billing_date')->nullable();
            $table->json('module_settings')->nullable();
            $table->json('usage_stats')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->boolean('is_auto_renew_enabled')->default(false);
            $table->timestamps();
            $table->unique(['organization_id', 'module_id']);
        });

        Schema::create('organization_package_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('subscription_id')->nullable();
            $table->boolean('is_bundled_with_plan')->default(false);
            $table->string('package_slug', 100);
            $table->string('tier', 50);
            $table->decimal('price_paid', 10, 2)->default(0);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'package_slug']);
        });
    }

    public function test_subscribe_activates_paid_package_and_returns_success_response(): void
    {
        foreach ([
            ['slug' => 'catalog-management', 'billing_model' => 'free', 'price' => 0],
            ['slug' => 'basic-warehouse', 'billing_model' => 'free', 'price' => 0],
            ['slug' => 'procurement', 'billing_model' => 'subscription', 'price' => 1990],
        ] as $moduleData) {
            Module::create([
                'name' => $moduleData['slug'],
                'slug' => $moduleData['slug'],
                'version' => '1.0.0',
                'type' => 'feature',
                'billing_model' => $moduleData['billing_model'],
                'category' => 'landing',
                'description' => 'Test module',
                'pricing_config' => [
                    'base_price' => $moduleData['price'],
                    'currency' => 'RUB',
                ],
                'features' => [],
                'permissions' => [],
                'dependencies' => [],
                'conflicts' => [],
                'limits' => [],
                'class_name' => null,
                'config_file' => null,
                'icon' => null,
                'display_order' => 1,
                'is_active' => true,
                'is_system_module' => false,
                'can_deactivate' => true,
            ]);
        }

        $balanceService = Mockery::mock(BalanceServiceInterface::class);
        $balanceService
            ->shouldReceive('getOrCreateOrganizationBalance')
            ->once()
            ->withArgs(fn (Organization $organization): bool => $organization->is($this->organization))
            ->andReturn(new OrganizationBalance([
                'organization_id' => $this->organization->id,
                'balance' => 790000,
                'currency' => 'RUB',
            ]));

        $balanceService
            ->shouldReceive('debitBalance')
            ->once()
            ->withArgs(function (Organization $organization, int $amount, string $description): bool {
                return $organization->is($this->organization)
                    && $amount === 790000
                    && $description !== '';
            })
            ->andReturnUsing(fn (Organization $organization) => $organization->balance()->create([
                'balance' => 0,
                'reserved_balance' => 0,
                'currency' => 'RUB',
                'is_active' => true,
            ]));

        $balanceService->shouldReceive('creditBalance')->never();
        $balanceService->shouldReceive('hasSufficientBalance')->never();

        $this->app->instance(BalanceServiceInterface::class, $balanceService);

        $token = JWTAuth::fromUser($this->user);

        $response = $this
            ->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/landing/packages/subscribe', [
                'package_slug' => 'supply-warehouse',
                'tier' => 'pro',
                'duration_days' => 30,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.package_slug', 'supply-warehouse')
            ->assertJsonPath('data.tier', 'pro')
            ->assertJsonPath('data.price_paid', 7900);

        $this->assertDatabaseHas('organization_package_subscriptions', [
            'organization_id' => $this->organization->id,
            'package_slug' => 'supply-warehouse',
            'tier' => 'pro',
        ]);

        $this->assertSame(
            3,
            OrganizationModuleActivation::query()
                ->where('organization_id', $this->organization->id)
                ->where('status', 'active')
                ->count()
        );

        $subscription = OrganizationPackageSubscription::query()
            ->where('organization_id', $this->organization->id)
            ->where('package_slug', 'supply-warehouse')
            ->first();

        $this->assertNotNull($subscription?->expires_at);
    }
}
