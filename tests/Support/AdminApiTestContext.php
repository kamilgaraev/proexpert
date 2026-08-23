<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Enums\AuthSessionStatus;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\Auth\JwtTokenIssuer;
use App\Services\Auth\WebAuthTokenService;
use Illuminate\Support\Str;

final readonly class AdminApiTestContext
{
    public function __construct(
        public Organization $organization,
        public User $user,
        public string $token
    ) {
    }

    public static function create(
        array $userAttributes = [],
        array $organizationAttributes = [],
        string $roleSlug = 'web_admin'
    ): self {
        $organization = Organization::factory()
            ->verified()
            ->create($organizationAttributes);

        $user = User::factory()->create(array_merge([
            'current_organization_id' => $organization->id,
        ], $userAttributes));

        $organization->users()->attach($user->id, [
            'is_owner' => true,
            'is_active' => true,
            'settings' => null,
        ]);

        $context = AuthorizationContext::getOrganizationContext($organization->id);

        UserRoleAssignment::assignRole(
            user: $user,
            roleSlug: $roleSlug,
            context: $context
        );

        $sessionUuid = (string) Str::uuid();
        UserAuthSession::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'session_uuid' => $sessionUuid,
            'device_fingerprint' => hash('sha256', $sessionUuid),
            'device_name' => 'Admin API test context',
            'ip_address' => '127.0.0.1',
            'risk_score' => 0,
            'risk_flags' => [],
            'status' => AuthSessionStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $tokens = app(WebAuthTokenService::class)->issue(
            $user,
            'admin',
            $sessionUuid,
            (int) $organization->id,
            false,
        );

        return new self($organization, $user, $tokens->accessToken);
    }

    public function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
            'Origin' => 'https://admin.1мост.рф',
        ];
    }

    public function mobileAuthHeaders(): array
    {
        $token = app(JwtTokenIssuer::class)->issue($this->user, [
            'guard' => 'api_mobile',
            'organization_id' => $this->organization->id,
        ]);

        return [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ];
    }
}
