<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing;

use App\Models\BalanceTransaction;
use App\Models\Organization;
use App\Models\OrganizationBalance;
use App\Models\OrganizationSubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EnterpriseConstructorCheckoutControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_charges_organization_balance_and_stores_constructor_limits(): void
    {
        $this->withoutMiddleware();

        [$organization, $user] = $this->createOrganizationWithOwner();
        $this->createEnterprisePlan();
        OrganizationBalance::query()->create([
            'organization_id' => $organization->id,
            'balance' => 20_000_000,
            'currency' => 'RUB',
        ]);

        $response = $this
            ->actingAs($user, 'api_landing')
            ->postJson('/api/v1/landing/billing/enterprise-constructor/checkout', [
                'users' => 250,
                'additional_organizations' => 1,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Enterprise Конструктор подключен. Оплата списана с баланса организации.')
            ->assertJsonPath('data.preview.price.total', 164000)
            ->assertJsonPath('data.preview.limits.users', 250)
            ->assertJsonPath('data.balance.amount', 3_600_000);

        $subscription = OrganizationSubscription::query()
            ->where('organization_id', $organization->id)
            ->firstOrFail();

        self::assertSame('active', $subscription->status);
        self::assertSame(250, $subscription->enterprise_constructor_config['limits']['users']);
        self::assertSame(100, $subscription->enterprise_constructor_config['limits']['foremen']);

        $this->assertDatabaseHas('organization_balances', [
            'organization_id' => $organization->id,
            'balance' => 3_600_000,
        ]);
        $this->assertDatabaseHas('balance_transactions', [
            'organization_subscription_id' => $subscription->id,
            'type' => BalanceTransaction::TYPE_DEBIT,
            'amount' => 16_400_000,
            'balance_before' => 20_000_000,
            'balance_after' => 3_600_000,
        ]);
    }

    public function test_checkout_with_implementation_project_does_not_charge_balance(): void
    {
        $this->withoutMiddleware();

        [$organization, $user] = $this->createOrganizationWithOwner();
        $this->createEnterprisePlan();
        OrganizationBalance::query()->create([
            'organization_id' => $organization->id,
            'balance' => 20_000_000,
            'currency' => 'RUB',
        ]);

        $response = $this
            ->actingAs($user, 'api_landing')
            ->postJson('/api/v1/landing/billing/enterprise-constructor/checkout', [
                'users' => 100,
                'needs_integrations' => true,
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Для выбранной конфигурации подготовим проект внедрения. Деньги с баланса не списаны.');

        $this->assertDatabaseMissing('organization_subscriptions', [
            'organization_id' => $organization->id,
        ]);
        $this->assertDatabaseHas('organization_balances', [
            'organization_id' => $organization->id,
            'balance' => 20_000_000,
        ]);
        $this->assertDatabaseCount('balance_transactions', 0);
    }

    public function test_checkout_returns_payment_required_when_balance_is_not_enough(): void
    {
        $this->withoutMiddleware();

        [$organization, $user] = $this->createOrganizationWithOwner();
        $this->createEnterprisePlan();
        OrganizationBalance::query()->create([
            'organization_id' => $organization->id,
            'balance' => 1_000_000,
            'currency' => 'RUB',
        ]);

        $response = $this
            ->actingAs($user, 'api_landing')
            ->postJson('/api/v1/landing/billing/enterprise-constructor/checkout', [
                'users' => 250,
            ]);

        $response
            ->assertStatus(402)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'На балансе организации недостаточно средств для подключения Enterprise Конструктора.');

        $this->assertDatabaseMissing('organization_subscriptions', [
            'organization_id' => $organization->id,
        ]);
        $this->assertDatabaseHas('organization_balances', [
            'organization_id' => $organization->id,
            'balance' => 1_000_000,
        ]);
        $this->assertDatabaseCount('balance_transactions', 0);
    }

    private function createEnterprisePlan(): SubscriptionPlan
    {
        return SubscriptionPlan::query()->updateOrCreate(
            ['slug' => 'enterprise'],
            [
            'name' => 'Enterprise Конструктор',
            'description' => 'Конструктор для крупных команд',
            'price' => 99000,
            'currency' => 'RUB',
            'duration_in_days' => 30,
            'max_users' => 100,
            'max_foremen' => 100,
            'max_projects' => 100,
            'max_storage_gb' => 50,
            'max_contractor_invitations' => 500,
            'features' => [],
            'included_packages' => [],
            'is_active' => true,
            'display_order' => 50,
            ]
        );
    }

    private function createOrganizationWithOwner(): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);

        $organization->users()->attach($user->id, [
            'is_owner' => true,
            'is_active' => true,
            'settings' => null,
        ]);

        return [$organization, $user];
    }
}
