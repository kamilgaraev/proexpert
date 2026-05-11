<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Organization;
use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;

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

        $token = JWTAuth::claims([
            'organization_id' => $organization->id,
        ])->fromUser($user);

        return new self($organization, $user, $token);
    }

    public function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ];
    }
}
