<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeProfile;
use App\BusinessModules\Contractors\Brigades\Domain\Services\BrigadeWorkflowService;
use App\DTOs\Auth\LoginDTO;
use App\Models\LandingAdmin;
use App\Models\Organization;
use App\Models\User;
use App\Services\Auth\JwtAuthService;
use App\Services\Auth\LandingAdminAuthService;
use App\Services\Customer\Auth\CustomerAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

use function trans_message;

final class InactiveUserAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_login_rejects_inactive_user(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive-landing@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => false,
        ]);

        $result = app(JwtAuthService::class)->authenticate(
            new LoginDTO($user->email, 'password'),
            'api_landing',
        );

        $this->assertFalse($result['success']);
        $this->assertSame(403, $result['status_code']);
        $this->assertArrayNotHasKey('token', $result);
        $this->assertSame(trans_message('auth.account_disabled'), $result['message']);
    }

    public function test_landing_login_endpoint_returns_forbidden_for_inactive_user(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive-landing-endpoint@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/v1/landing/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', trans_message('auth.account_disabled'));

        $payload = $response->json();

        $this->assertArrayNotHasKey('token', $payload);
        $this->assertArrayNotHasKey('data', $payload);
    }

    public function test_customer_login_rejects_inactive_user(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'email' => 'inactive-customer@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => false,
            'current_organization_id' => $organization->id,
        ]);
        $user->organizations()->attach($organization->id, [
            'is_owner' => true,
            'is_active' => true,
        ]);

        $result = app(CustomerAuthService::class)->login(
            new LoginDTO($user->email, 'password'),
            'api_landing',
        );

        $this->assertFalse($result['success']);
        $this->assertSame(403, $result['status_code']);
        $this->assertArrayNotHasKey('token', $result);
        $this->assertSame(trans_message('auth.account_disabled'), $result['message']);
    }

    public function test_brigade_login_rejects_inactive_user(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive-brigade@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => false,
        ]);

        BrigadeProfile::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Inactive Brigade',
            'slug' => 'inactive-brigade',
            'team_size' => 3,
            'contact_person' => 'Inactive User',
            'contact_phone' => '+79990000000',
            'contact_email' => $user->email,
            'regions' => ['moscow'],
        ]);

        $result = app(BrigadeWorkflowService::class)->authenticate([
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertNull($result);
    }

    public function test_landing_admin_login_rejects_inactive_user(): void
    {
        $admin = LandingAdmin::query()->create([
            'name' => 'Inactive Landing Admin',
            'email' => 'inactive-landing-admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => false,
        ]);

        $result = app(LandingAdminAuthService::class)->authenticate(
            new LoginDTO($admin->email, 'password'),
        );

        $this->assertFalse($result['success']);
        $this->assertSame(403, $result['status_code']);
        $this->assertArrayNotHasKey('token', $result);
        $this->assertSame(trans_message('auth.account_disabled'), $result['message']);
    }

    public function test_landing_admin_login_endpoint_returns_forbidden_for_inactive_user(): void
    {
        $admin = LandingAdmin::query()->create([
            'name' => 'Inactive Landing Admin Endpoint',
            'email' => 'inactive-landing-admin-endpoint@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/v1/landing/landingAdminAuth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $response
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', trans_message('auth.account_disabled'));

        $payload = $response->json();

        $this->assertArrayNotHasKey('token', $payload);
    }
}
