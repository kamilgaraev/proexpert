<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\ProjectOrganizationRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Notifications\EmailVerificationNotification;
use App\Services\Analytics\EVMService;
use App\Services\Project\ProjectParticipantService;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

class CustomerAdminAccessTest extends TestCase
{
    public function test_customer_registration_returns_admin_interface_and_grants_access_to_admin_ui(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/customer/auth/register', [
            'name' => 'Заказчик Тест',
            'email' => 'customer-admin@example.com',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
            'organization_name' => 'ООО Заказчик Тест',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'verification_required');

        $interfaces = $response->json('data.available_interfaces');

        $this->assertContains('customer', $interfaces);
        $this->assertContains('admin', $interfaces);

        $user = User::query()->where('email', 'customer-admin@example.com')->firstOrFail();
        $authService = app(AuthorizationService::class);

        $this->assertTrue($authService->canAccessInterface($user, 'customer'));
        $this->assertTrue($authService->canAccessInterface($user, 'admin'));

        Notification::assertSentTo($user, EmailVerificationNotification::class);
    }

    public function test_customer_login_returns_admin_interface_after_registration(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/customer/auth/register', [
            'name' => 'Логин Заказчик',
            'email' => 'customer-login@example.com',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
            'organization_name' => 'ООО Логин Заказчик',
        ])->assertCreated();

        $user = User::query()->where('email', 'customer-login@example.com')->firstOrFail();
        $user->forceFill([
            'email_verified_at' => now(),
        ])->save();

        $response = $this->postJson('/api/v1/customer/auth/login', [
            'email' => 'customer-login@example.com',
            'password' => 'Secret123!',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email_verified', true);

        $interfaces = $response->json('data.available_interfaces');

        $this->assertContains('customer', $interfaces);
        $this->assertContains('admin', $interfaces);
    }

    public function test_customer_project_participant_can_access_admin_evm_metrics_and_forecast(): void
    {
        $this->withoutMiddleware();

        [$project, $ownerUser] = $this->createProjectWithOwner();
        [$customerOrganization, $customerUser] = $this->createOrganizationUser('customer-evm@example.com');

        app(ProjectParticipantService::class)->attach(
            $project,
            $customerOrganization->id,
            ProjectOrganizationRole::CUSTOMER,
            $ownerUser
        );

        $this->mockEvmService();

        $metricsResponse = $this->actingAs($customerUser, 'api_admin')
            ->getJson("/api/v1/admin/dashboard/evm/metrics?project_id={$project->id}");

        $metricsResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.spi', 1.02)
            ->assertJsonPath('data.cpi', 0.98);

        $forecastResponse = $this->actingAs($customerUser, 'api_admin')
            ->getJson("/api/v1/admin/dashboard/evm/forecast?project_id={$project->id}");

        $forecastResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.eac', 1050000.0)
            ->assertJsonPath('data.vac', -50000.0);
    }

    public function test_project_owner_keeps_admin_evm_access(): void
    {
        $this->withoutMiddleware();

        [$project, $ownerUser] = $this->createProjectWithOwner();
        $this->mockEvmService();

        $response = $this->actingAs($ownerUser, 'api_admin')
            ->getJson("/api/v1/admin/dashboard/evm/metrics?project_id={$project->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.spi', 1.02);
    }

    public function test_project_contractor_gets_scoped_admin_evm_access(): void
    {
        $this->withoutMiddleware();

        [$project, $ownerUser] = $this->createProjectWithOwner();
        [$contractorOrganization, $contractorUser] = $this->createOrganizationUser('contractor-evm@example.com');

        app(ProjectParticipantService::class)->attach(
            $project,
            $contractorOrganization->id,
            ProjectOrganizationRole::CONTRACTOR,
            $ownerUser
        );

        $this->mockScopedEvmService($contractorOrganization->id);

        $metricsResponse = $this->actingAs($contractorUser, 'api_admin')
            ->getJson("/api/v1/admin/dashboard/evm/metrics?project_id={$project->id}");

        $metricsResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.spi', 1.02)
            ->assertJsonPath('data.cpi', 0.98);
    }

    public function test_user_without_project_access_gets_403_for_admin_evm(): void
    {
        $this->withoutMiddleware();

        [$project] = $this->createProjectWithOwner();
        [, $outsiderUser] = $this->createOrganizationUser('outsider-evm@example.com');
        $this->mockEvmService();

        $response = $this->actingAs($outsiderUser, 'api_admin')
            ->getJson("/api/v1/admin/dashboard/evm/metrics?project_id={$project->id}");

        $response->assertForbidden()
            ->assertJsonPath('success', false);
    }

    private function createProjectWithOwner(): array
    {
        [$organization, $user] = $this->createOrganizationUser('owner-evm@example.com');

        $project = Project::factory()->create([
            'organization_id' => $organization->id,
        ]);

        return [$project, $user];
    }

    private function createOrganizationUser(string $email): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'email' => $email,
            'current_organization_id' => $organization->id,
        ]);

        $organization->users()->attach($user->id, [
            'is_owner' => true,
            'is_active' => true,
        ]);

        return [$organization, $user];
    }

    private function mockEvmService(): void
    {
        $mock = Mockery::mock(EVMService::class);
        $mock->shouldReceive('calculateMetrics')
            ->with(Mockery::type(Project::class), null)
            ->andReturn([
                'bac' => 1000000.0,
                'pv' => 520000.0,
                'ev' => 510000.0,
                'ac' => 520408.16,
                'sv' => -10000.0,
                'cv' => -10408.16,
                'spi' => 1.02,
                'cpi' => 0.98,
                'eac' => 1050000.0,
                'vac' => -50000.0,
                'tcpi' => 1.05,
            ]);

        $this->app->instance(EVMService::class, $mock);
    }

    private function mockScopedEvmService(int $organizationId): void
    {
        $mock = Mockery::mock(EVMService::class);
        $mock->shouldReceive('calculateMetrics')
            ->once()
            ->with(Mockery::type(Project::class), $organizationId)
            ->andReturn([
                'bac' => 250000.0,
                'pv' => 125000.0,
                'ev' => 130000.0,
                'ac' => 128000.0,
                'sv' => 5000.0,
                'cv' => 2000.0,
                'spi' => 1.02,
                'cpi' => 0.98,
                'eac' => 255000.0,
                'vac' => -5000.0,
                'tcpi' => 1.01,
            ]);

        $this->app->instance(EVMService::class, $mock);
    }
}
