<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\RoleScanner;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SystemRoleMobileAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_access_respects_system_role_and_assignment_boundaries(): void
    {
        $organization = Organization::factory()->create();
        $context = AuthorizationContext::getOrganizationContext($organization->id);
        $authorization = app(AuthorizationService::class);

        foreach (app(RoleScanner::class)->getAllRoles() as $slug => $role) {
            $user = User::factory()->create();
            UserRoleAssignment::create([
                'user_id' => $user->id,
                'context_id' => $context->id,
                'role_slug' => $slug,
                'role_type' => UserRoleAssignment::TYPE_SYSTEM,
                'is_active' => true,
            ]);

            self::assertSame(
                !str_starts_with($slug, 'customer_'),
                $authorization->canAccessInterface($user, 'mobile', $context),
                $slug,
            );
        }

        foreach ([['is_active' => false], ['is_active' => true, 'expires_at' => now()->subDay()]] as $state) {
            $user = User::factory()->create();
            UserRoleAssignment::create([
                'user_id' => $user->id,
                'context_id' => $context->id,
                'role_slug' => 'supplier',
                'role_type' => UserRoleAssignment::TYPE_SYSTEM,
                ...$state,
            ]);
            self::assertFalse($authorization->canAccessInterface($user, 'mobile', $context));
        }

        self::assertFalse($authorization->canAccessInterface(User::factory()->create(), 'mobile', $context));
    }
}
