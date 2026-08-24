<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\DTOs\Auth\LoginDTO;
use App\Enums\AuthSessionStatus;
use App\Models\Organization;
use App\Models\User;
use App\Services\Customer\Auth\CustomerAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

final class ActiveOrganizationMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_request_rejects_organization_claim_after_membership_is_deactivated(): void
    {
        [$user, $organization, $token] = $this->authenticatedCustomer();

        $user->organizations()->updateExistingPivot($organization->id, ['is_active' => false]);

        $this->withToken($token)
            ->getJson('/api/v1/customer/dashboard')
            ->assertForbidden();
    }

    public function test_customer_request_does_not_fallback_when_claimed_membership_is_inactive(): void
    {
        [$user, $claimedOrganization, $token] = $this->authenticatedCustomer();
        $otherOrganization = Organization::factory()->create();
        $user->organizations()->attach($otherOrganization->id, [
            'is_owner' => false,
            'is_active' => true,
        ]);
        $user->organizations()->updateExistingPivot($claimedOrganization->id, ['is_active' => false]);

        $this->withToken($token)
            ->getJson('/api/v1/customer/dashboard')
            ->assertForbidden();

        self::assertSame($claimedOrganization->id, $user->fresh()->current_organization_id);
    }

    public function test_legacy_refresh_revokes_session_after_membership_is_deactivated(): void
    {
        [$user, $organization, $token] = $this->authenticatedCustomer();
        $sessionUuid = (string) JWTAuth::setToken($token)->getPayload()->get('session_uuid');

        $user->organizations()->updateExistingPivot($organization->id, ['is_active' => false]);

        $this->withToken($token)
            ->postJson('/api/v1/customer/auth/refresh')
            ->assertForbidden();

        $this->assertDatabaseHas('user_auth_sessions', [
            'session_uuid' => $sessionUuid,
            'status' => AuthSessionStatus::Revoked->value,
            'revoked_reason' => 'organization_membership_inactive',
        ]);
    }

    /** @return array{User, Organization, string} */
    private function authenticatedCustomer(): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
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

        self::assertTrue($result['success']);
        self::assertIsString($result['token']);

        return [$user, $organization, $result['token']];
    }
}
