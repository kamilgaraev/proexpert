<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Contractor;
use App\Models\ContractorVerification;
use App\Models\Organization;
use App\Models\User;
use App\Services\Auth\JwtTokenIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

final class JwtRequestStateIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_mobile_request_uses_its_own_bearer_subject_and_organization(): void
    {
        Gate::define('access-mobile-app', static fn (): bool => true);
        [$firstUser, $firstToken] = $this->createMobileActor();
        [$secondUser, $secondToken] = $this->createMobileActor();

        $this->withToken($firstToken)
            ->getJson('/api/v1/mobile/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $firstUser->id)
            ->assertJsonPath('data.current_organization_id', $firstUser->current_organization_id);

        $firstFacadePayload = JWTAuth::setToken($firstToken)->getPayload();
        $this->assertSame((int) $firstUser->id, (int) $firstFacadePayload->get('sub'));
        $this->assertSame((int) $firstUser->current_organization_id, (int) $firstFacadePayload->get('organization_id'));

        $this->withToken($secondToken)
            ->getJson('/api/v1/mobile/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $secondUser->id)
            ->assertJsonPath('data.current_organization_id', $secondUser->current_organization_id);

        $secondFacadePayload = JWTAuth::parseToken()->getPayload();
        $this->assertSame((int) $secondUser->id, (int) $secondFacadePayload->get('sub'));
        $this->assertSame((int) $secondUser->current_organization_id, (int) $secondFacadePayload->get('organization_id'));
    }

    public function test_missing_bearer_after_a_valid_mobile_request_is_unauthorized(): void
    {
        Gate::define('access-mobile-app', static fn (): bool => true);
        [, $token] = $this->createMobileActor();

        $this->withToken($token)->getJson('/api/v1/mobile/auth/me')->assertOk();

        $this->withoutHeader('Authorization')
            ->getJson('/api/v1/mobile/auth/me')
            ->assertUnauthorized();
    }

    public function test_invalid_bearer_after_a_valid_mobile_request_is_unauthorized(): void
    {
        Gate::define('access-mobile-app', static fn (): bool => true);
        [, $token] = $this->createMobileActor();

        $this->withToken($token)->getJson('/api/v1/mobile/auth/me')->assertOk();

        $this->withToken('not-a-valid-token')
            ->getJson('/api/v1/mobile/auth/me')
            ->assertUnauthorized();
    }

    public function test_public_contractor_confirmation_does_not_inherit_mobile_guard_or_actor(): void
    {
        config(['auth.default_guard' => 'web', 'auth.defaults.guard' => 'web']);
        Gate::define('access-mobile-app', static fn (): bool => true);
        [$user, $token] = $this->createMobileActor();
        $verification = $this->createPendingVerification();

        $this->withToken($token)
            ->getJson('/api/v1/mobile/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->withToken($token)
            ->postJson("/api/v1/contractor-verifications/{$verification->verification_token}/confirm")
            ->assertOk();

        $this->assertSame('confirmed', $verification->fresh()->status);
        $this->assertNull($verification->fresh()->confirmed_by_user_id);
        $this->assertSame('web', app('auth')->getDefaultDriver());
    }

    public function test_public_contract_confirmation_restores_configured_non_web_default_guard(): void
    {
        config(['auth.default_guard' => 'api_admin', 'auth.defaults.guard' => 'api_admin']);
        Gate::define('access-mobile-app', static fn (): bool => true);
        [, $token] = $this->createMobileActor();
        $verification = $this->createPendingVerification();

        $this->withToken($token)->getJson('/api/v1/mobile/auth/me')->assertOk();

        $this->withoutHeader('Authorization')
            ->postJson("/api/v1/contractor-verifications/{$verification->verification_token}/confirm")
            ->assertOk();

        $this->assertNull($verification->fresh()->confirmed_by_user_id);
        $this->assertSame('api_admin', app('auth')->getDefaultDriver());
    }

    /** @return array{User, string} */
    private function createMobileActor(): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'is_active' => true,
            'current_organization_id' => $organization->id,
        ]);
        $user->organizations()->attach($organization->id, [
            'is_owner' => true,
            'is_active' => true,
        ]);

        return [
            $user,
            app(JwtTokenIssuer::class)->issue($user, [
                'guard' => 'api_mobile',
                'organization_id' => (int) $organization->id,
            ]),
        ];
    }

    private function createPendingVerification(): ContractorVerification
    {
        $customerOrganization = Organization::factory()->create();
        $registeredOrganization = Organization::factory()->create();
        $contractor = Contractor::query()->create([
            'organization_id' => $customerOrganization->id,
            'source_organization_id' => $registeredOrganization->id,
            'name' => 'JWT guard regression contractor',
        ]);

        return ContractorVerification::query()->create([
            'contractor_id' => $contractor->id,
            'registered_organization_id' => $registeredOrganization->id,
            'customer_organization_id' => $customerOrganization->id,
            'status' => 'pending_customer_confirmation',
            'verification_score' => 10,
            'verification_data' => [],
            'verified_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
    }
}
