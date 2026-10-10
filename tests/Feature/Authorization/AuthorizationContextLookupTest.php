<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\Models\AuthorizationContext;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AuthorizationContextLookupTest extends TestCase
{
    public function test_existing_contexts_resolve_their_full_parent_chain_in_one_query_each(): void
    {
        $organization = AuthorizationContext::getOrganizationContext(910001);
        $project = AuthorizationContext::getProjectContext(910002, 910001);
        $connection = DB::connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();

        try {
            self::assertSame($organization->id, AuthorizationContext::getOrganizationContext(910001)->id);
            self::assertSame($project->id, AuthorizationContext::getProjectContext(910002, 910001)->id);
            self::assertSame($organization->id, AuthorizationContext::findOrganizationContext(910001)?->id);
            self::assertSame($project->id, AuthorizationContext::findProjectContext(910002, 910001)?->id);
            $queries = $connection->getQueryLog();
            self::assertCount(4, $queries);
            foreach ($queries as $query) {
                self::assertStringStartsWith('select ', $query['query']);
            }
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }
    }

    public function test_new_lookups_observe_metadata_and_reparenting_without_cross_organization_matches(): void
    {
        $organization = AuthorizationContext::getOrganizationContext(910001);
        $foreignOrganization = AuthorizationContext::getOrganizationContext(910003);
        $project = AuthorizationContext::getProjectContext(910002, 910001);

        self::assertNull(AuthorizationContext::findProjectContext(910002, 910003));
        $project->update(['metadata' => ['revision' => 2], 'parent_context_id' => $foreignOrganization->id]);

        self::assertNull(AuthorizationContext::findProjectContext(910002, 910001));
        self::assertSame(['revision' => 2], AuthorizationContext::findProjectContext(910002, 910003)?->metadata);
        self::assertSame($project->id, AuthorizationContext::getProjectContext(910002, 910003)->id);

        $restored = AuthorizationContext::getProjectContext(910002, 910001);
        self::assertNotSame($project->id, $restored->id);
        self::assertSame($organization->id, $restored->parent_context_id);
    }

    public function test_missing_ancestors_fail_closed_without_writes_and_getters_still_create_the_chain(): void
    {
        $count = AuthorizationContext::query()->count();
        self::assertNull(AuthorizationContext::findOrganizationContext(910001));
        self::assertNull(AuthorizationContext::findProjectContext(910002, 910001));
        self::assertSame($count, AuthorizationContext::query()->count());

        $project = AuthorizationContext::getProjectContext(910002, 910001);
        $organization = AuthorizationContext::findOrganizationContext(910001);
        self::assertNotNull($organization);
        self::assertSame($organization->id, $project->parent_context_id);
        self::assertSame(AuthorizationContext::findSystemContext()?->id, $organization->parent_context_id);
        self::assertSame($project->id, AuthorizationContext::findProjectContext(910002, 910001)?->id);
    }

    public function test_existing_orphan_contexts_do_not_match_a_valid_parent_chain(): void
    {
        $wrongRoot = AuthorizationContext::query()->create(['type' => 'project', 'resource_id' => 910004]);
        $organization = AuthorizationContext::query()->create([
            'type' => 'organization',
            'resource_id' => 910001,
            'parent_context_id' => $wrongRoot->id,
        ]);
        AuthorizationContext::query()->create([
            'type' => 'project',
            'resource_id' => 910002,
            'parent_context_id' => $organization->id,
        ]);
        $count = AuthorizationContext::query()->count();

        self::assertNull(AuthorizationContext::findOrganizationContext(910001));
        self::assertNull(AuthorizationContext::findProjectContext(910002, 910001));
        self::assertSame($count, AuthorizationContext::query()->count());
    }
}
