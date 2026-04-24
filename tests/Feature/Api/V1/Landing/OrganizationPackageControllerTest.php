<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing;

use App\Interfaces\Billing\BalanceServiceInterface;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleActivation;
use App\Models\OrganizationPackageSubscription;
use App\Models\User;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class OrganizationPackageControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private Organization $organization;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        $this->organization = Organization::factory()->create();

        $this->user = User::factory()->create([
            'current_organization_id' => $this->organization->id,
        ]);

        $this->organization->users()->attach($this->user->id, [
            'is_owner' => true,
            'is_active' => true,
        ]);
    }

    public function test_subscribe_activates_paid_package_and_returns_success_response(): void
    {
        foreach ([
            ['slug' => 'catalog-management', 'billing_model' => 'free', 'price' => 0],
            ['slug' => 'basic-warehouse', 'billing_model' => 'free', 'price' => 0],
            ['slug' => 'procurement', 'billing_model' => 'subscription', 'price' => 1990],
            ['slug' => 'rate-management', 'billing_model' => 'subscription', 'price' => 1990],
            ['slug' => 'material-analytics', 'billing_model' => 'subscription', 'price' => 1510],
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
            ->shouldReceive('debitBalance')
            ->once()
            ->withArgs(function (Organization $organization, int $amount, string $description): bool {
                return $organization->is($this->organization)
                    && $amount === 549000
                    && $description !== '';
            })
            ->andReturnUsing(fn (Organization $organization) => $organization->balance()->create([
                'balance' => 0,
                'reserved_balance' => 0,
                'currency' => 'RUB',
                'is_active' => true,
            ]));

        $balanceService->shouldReceive('getOrCreateOrganizationBalance')->never();
        $balanceService->shouldReceive('creditBalance')->never();
        $balanceService->shouldReceive('hasSufficientBalance')->never();

        $this->app->instance(BalanceServiceInterface::class, $balanceService);

        $response = $this->actingAs($this->user, 'api_landing')
            ->postJson('/api/v1/landing/packages/subscribe', [
                'package_slug' => 'supply',
                'tier' => 'pro',
                'duration_days' => 30,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.package_slug', 'supply')
            ->assertJsonPath('data.tier', 'pro')
            ->assertJsonPath('data.price_paid', 5490);

        $this->assertDatabaseHas('organization_package_subscriptions', [
            'organization_id' => $this->organization->id,
            'package_slug' => 'supply',
            'tier' => 'pro',
        ]);

        $this->assertSame(
            5,
            OrganizationModuleActivation::query()
                ->where('organization_id', $this->organization->id)
                ->where('status', 'active')
                ->count()
        );

        $subscription = OrganizationPackageSubscription::query()
            ->where('organization_id', $this->organization->id)
            ->where('package_slug', 'supply')
            ->first();

        $this->assertNotNull($subscription?->expires_at);
    }
}
