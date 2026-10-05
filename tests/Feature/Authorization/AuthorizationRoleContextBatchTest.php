<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AuthorizationRoleContextBatchTest extends TestCase
{
    public function test_roles_load_context_and_parent_together_without_marking_grandparents_loaded(): void
    {
        [$user, $system, $organization, $project] = $this->fixture();
        $reads = 0;
        DB::listen(static function ($query) use (&$reads): void {
            if (str_contains($query->sql, 'authorization_contexts')) {
                $reads++;
            }
        });
        $roles = app(AuthorizationService::class)->forCurrentChecks(true)->getUserRoles($user);
        self::assertCount(2, $roles);
        self::assertSame(1, $reads);
        $context = $roles->firstWhere('context_id', $project->id)->context;
        self::assertSame($project->id, $context->id);
        self::assertTrue($context->relationLoaded('parentContext'));
        self::assertSame($organization->id, $context->parentContext->id);
        self::assertFalse($context->parentContext->relationLoaded('parentContext'));
        self::assertSame($system->id, $context->parentContext->parentContext->id);
    }

    public function test_next_fresh_scope_observes_reparenting_and_role_revocation(): void
    {
        [$user, $system, $organization, $project] = $this->fixture();
        $first = app(AuthorizationService::class)->forCurrentChecks(true)->getUserRoles($user);
        $replacement = AuthorizationContext::query()->create(['type' => 'organization', 'resource_id' => 999, 'parent_context_id' => $system->id]);
        DB::table('authorization_contexts')->where('id', $project->id)->update(['parent_context_id' => $replacement->id]);
        $next = app(AuthorizationService::class)->forCurrentChecks(true)->getUserRoles($user);
        self::assertSame($organization->id, $first->firstWhere('context_id', $project->id)->context->parentContext->id);
        self::assertSame($replacement->id, $next->firstWhere('context_id', $project->id)->context->parentContext->id);
        DB::table('user_role_assignments')->where('user_id', $user->id)->update(['is_active' => false]);
        self::assertCount(0, app(AuthorizationService::class)->forCurrentChecks(true)->getUserRoles($user));
    }

    private function fixture(): array
    {
        $user = User::factory()->create();
        $system = AuthorizationContext::query()->create(['type' => 'system']);
        $organization = AuthorizationContext::query()->create(['type' => 'organization', 'resource_id' => 998, 'parent_context_id' => $system->id]);
        $project = AuthorizationContext::query()->create(['type' => 'project', 'resource_id' => 997, 'parent_context_id' => $organization->id]);
        foreach ([$organization, $project] as $context) {
            UserRoleAssignment::query()->create(['user_id' => $user->id, 'context_id' => $context->id,
                'role_type' => 'system', 'role_slug' => 'organization_owner', 'assigned_by' => $user->id, 'is_active' => true]);
        }

        return [$user, $system, $organization, $project];
    }
}
