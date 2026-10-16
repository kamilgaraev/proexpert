<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AssistantOrganizationMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_membership_is_one_fresh_query_and_pivot_revocation_is_immediate(): void
    {
        [$actor, $organization] = $this->membership();
        $policy = app(AssistantDataAccessPolicy::class);

        $this->assertMembership($policy, $actor, $organization, true, 1);
        $this->assertMembership($policy, $actor, $organization, true, 1);
        DB::table('organization_user')->where('user_id', $actor->id)->where('organization_id', $organization->id)->update(['is_active' => false]);
        $this->assertMembership($policy, $actor, $organization, false, 1);
        DB::table('organization_user')->where('user_id', $actor->id)->where('organization_id', $organization->id)->delete();
        $this->assertMembership($policy, $actor, $organization, false, 1);
    }

    #[DataProvider('revokedUserStates')]
    public function test_database_user_revocation_overrides_the_unchanged_actor(string $attribute, mixed $value): void
    {
        [$actor, $organization] = $this->membership();
        $policy = app(AssistantDataAccessPolicy::class);
        $this->assertMembership($policy, $actor, $organization, true, 1);

        DB::table('users')->where('id', $actor->id)->update([$attribute => $value]);

        $this->assertTrue($actor->is_active);
        $this->assertSame((int) $organization->id, (int) $actor->current_organization_id);
        $this->assertMembership($policy, $actor, $organization, false, 1);
    }

    public static function revokedUserStates(): array
    {
        return [
            'inactive' => ['is_active', false],
            'different_current_organization' => ['current_organization_id', null],
            'soft_deleted' => ['deleted_at', '2026-10-16 00:00:00'],
        ];
    }

    public function test_foreign_membership_and_deleted_organization_cannot_grant_access(): void
    {
        [$actor, $organization] = $this->membership();
        $foreign = Organization::factory()->create();
        $actor->organizations()->attach($foreign->id, ['is_active' => true]);
        $policy = app(AssistantDataAccessPolicy::class);
        DB::table('organization_user')->where('user_id', $actor->id)->where('organization_id', $organization->id)->update(['is_active' => false]);

        $this->assertMembership($policy, $actor, $organization, false, 1);
        DB::table('organization_user')->where('user_id', $actor->id)->where('organization_id', $organization->id)->update(['is_active' => true]);
        $this->assertMembership($policy, $actor, $organization, true, 1);
        DB::table('organizations')->where('id', $organization->id)->update(['deleted_at' => now()]);
        $this->assertMembership($policy, $actor, $organization, false, 1);
    }

    public function test_in_memory_actor_guards_do_not_query_membership(): void
    {
        [$actor, $organization] = $this->membership();
        $policy = app(AssistantDataAccessPolicy::class);
        $actor->is_active = false;
        $this->assertMembership($policy, $actor, $organization, false, 0);
        $actor->is_active = true;
        $actor->current_organization_id = null;
        $this->assertMembership($policy, $actor, $organization, false, 0);
    }

    public function test_subclass_membership_override_and_short_circuit_are_preserved(): void
    {
        [$actor, $organization] = $this->membership();
        $custom = new class extends User {
            public bool $membershipAllowed = false;
            public int $membershipCalls = 0;

            public function belongsToOrganization(int $organizationId): bool
            {
                $this->membershipCalls++;

                return $this->membershipAllowed;
            }
        };
        $custom->setRawAttributes($actor->getAttributes(), true);
        $custom->setTable($actor->getTable());
        $policy = app(AssistantDataAccessPolicy::class);

        $this->assertMembership($policy, $custom, $organization, false, 1);
        $this->assertSame(1, $custom->membershipCalls);
        $custom->membershipAllowed = true;
        $this->assertMembership($policy, $custom, $organization, true, 1);
        $this->assertSame(2, $custom->membershipCalls);
        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        $this->assertMembership($policy, $custom, $organization, false, 1);
        $this->assertSame(2, $custom->membershipCalls);
    }

    public function test_distinct_connection_objects_keep_the_original_two_query_path(): void
    {
        [$actor, $organization] = $this->membership();
        $connection = DB::connection();
        $alias = 'assistant_membership_test';
        config(['database.connections.'.$alias => config('database.connections.'.DB::getDefaultConnection())]);
        $separate = DB::connection($alias);
        $separate->setPdo($connection->getPdo());
        $separate->setReadPdo($connection->getReadPdo());
        $actor->setConnection($alias);
        $this->assertNotSame($connection, $separate);
        $connection->enableQueryLog();
        $separate->enableQueryLog();
        $connection->flushQueryLog();
        $separate->flushQueryLog();

        try {
            $this->assertTrue(app(AssistantDataAccessPolicy::class)->belongsToOrganization($actor, (int) $organization->id));
            $this->assertCount(1, $connection->getQueryLog());
            $this->assertCount(1, $separate->getQueryLog());
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
            $separate->disableQueryLog();
            $separate->flushQueryLog();
            DB::purge($alias);
        }
    }

    public function test_user_and_organization_global_scopes_remain_effective(): void
    {
        [$actor, $organization] = $this->membership();
        $policy = app(AssistantDataAccessPolicy::class);
        $scopes = User::getAllGlobalScopes();

        try {
            Organization::addGlobalScope('membership_test', static fn (Builder $query) => $query->whereRaw('1 = 0'));
            $this->assertMembership($policy, $actor, $organization, false, 1);
            User::setAllGlobalScopes($scopes);
            User::addGlobalScope('membership_test', static fn (Builder $query) => $query->whereRaw('1 = 0'));
            $this->assertMembership($policy, $actor, $organization, false, 1);
        } finally {
            User::setAllGlobalScopes($scopes);
        }
    }

    private function membership(): array
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true]);

        return [$actor, $organization];
    }

    private function assertMembership(AssistantDataAccessPolicy $policy, User $actor, Organization $organization, bool $expected, int $queries): void
    {
        $connection = DB::connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();

        try {
            $this->assertSame($expected, $policy->belongsToOrganization($actor, (int) $organization->id));
            $this->assertCount($queries, $connection->getQueryLog());
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }
    }
}
