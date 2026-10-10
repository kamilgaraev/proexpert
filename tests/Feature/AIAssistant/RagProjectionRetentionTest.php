<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\RagExpectedSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagExpectedSourceProjection;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\Models\Organization;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class RagProjectionRetentionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_cleanup_protects_active_generation_fresh_staging_and_other_organizations(): void
    {
        $organization = Organization::factory()->create();
        $foreign = Organization::factory()->create();
        $active = (string) Str::uuid();
        $obsolete = (string) Str::uuid();
        $fresh = (string) Str::uuid();
        $this->rows($organization->id, $active, 2, now()->subDays(2));
        $this->rows($organization->id, $obsolete, 3, now()->subDays(2));
        $this->rows($organization->id, $fresh, 2, now()->subHours(2));
        $this->rows($foreign->id, $obsolete, 2, now()->subDays(2));
        $revision = (int) Cache::get('ai-rag-coverage-revision:'.$organization->id, 0);
        $key = 'ai-rag-coverage:'.$organization->id.':0:*:'.$revision;
        Cache::put($key, ['projection_generation' => $active], 300);
        $this->assertSame($active, Cache::get($key)['projection_generation'] ?? null);

        $result = $this->projection()->pruneOrganization($organization->id);

        $this->assertSame(3, $result['deleted'], json_encode([
            'revision_before' => $revision,
            'revision_after' => Cache::get('ai-rag-coverage-revision:'.$organization->id, 0),
            'active_snapshot' => Cache::get($key),
        ], JSON_THROW_ON_ERROR));
        $this->assertFalse($result['locked']);
        $this->assertSame(2, RagExpectedSource::query()->where('generation', $active)->count());
        $this->assertSame(2, RagExpectedSource::query()->where('generation', $fresh)->count());
        $this->assertSame(2, RagExpectedSource::query()->where('organization_id', $foreign->id)->count());
        $this->assertSame(0, $this->projection()->pruneOrganization($organization->id)['deleted']);
    }

    public function test_cleanup_runs_during_full_refresh_without_deleting_its_staging_generation(): void
    {
        $organization = Organization::factory()->create();
        $this->rows($organization->id, (string) Str::uuid(), 2, now()->subDays(2));
        $building = (string) Str::uuid();
        $this->rows($organization->id, $building, 2, now()->subSeconds(7500));
        $lease = Cache::lock('ai-rag-coverage-projection:'.$organization->id, 7500);
        $this->assertTrue($lease->get());
        try {
            $this->assertSame(['deleted' => 2, 'locked' => false], $this->projection()->pruneOrganization($organization->id));
            $this->assertSame(2, RagExpectedSource::query()->where('generation', $building)->count());
        } finally {
            $lease->release();
        }
    }

    public function test_cleanup_pages_both_uuid_ranges_without_crossing_scope_or_safety_boundaries(): void
    {
        $this->travelTo(now()->startOfSecond());
        $organization = Organization::factory()->create();
        $foreign = Organization::factory()->create();
        $previous = '10000000-0000-4000-8000-000000000001';
        $active = '80000000-0000-4000-8000-000000000001';
        $next = 'f0000000-0000-4000-8000-000000000001';
        $fresh = 'f0000000-0000-4000-8000-000000000002';
        $boundary = 'f0000000-0000-4000-8000-000000000003';
        $this->rows($organization->id, $previous, 600, now()->subHours(4));
        $this->rows($organization->id, $next, 600, now()->subHours(4));
        $this->rows($organization->id, $active, 2, now()->subHours(4));
        $this->rows($organization->id, $fresh, 2, now()->subHours(2));
        $this->rows($organization->id, $boundary, 2, now()->subHours(3));
        $this->rows($foreign->id, $next, 2, now()->subHours(4));
        $revision = (int) Cache::get('ai-rag-coverage-revision:'.$organization->id, 0);
        Cache::put('ai-rag-coverage:'.$organization->id.':0:*:'.$revision, ['projection_generation' => $active], 300);
        $deleteBatchSizes = [];
        DB::listen(static function ($event) use (&$deleteBatchSizes): void {
            if (str_starts_with(strtolower($event->sql), 'delete') && str_contains($event->sql, 'ai_rag_expected_sources')) {
                $deleteBatchSizes[] = count($event->bindings) - 1;
            }
        });

        $this->assertSame(1005, $this->projection()->pruneOrganization($organization->id, 1005)['deleted']);
        $this->assertSame([1000, 5], $deleteBatchSizes);
        $this->assertSame(195, $this->projection()->pruneOrganization($organization->id)['deleted']);
        $this->assertSame(0, RagExpectedSource::query()->where('organization_id', $organization->id)->whereIn('generation', [$previous, $next])->count());
        $this->assertSame(2, RagExpectedSource::query()->where('organization_id', $organization->id)->where('generation', $active)->count());
        $this->assertSame(2, RagExpectedSource::query()->where('generation', $fresh)->count());
        $this->assertSame(2, RagExpectedSource::query()->where('generation', $boundary)->count());
        $this->assertSame(2, RagExpectedSource::query()->where('organization_id', $foreign->id)->count());
    }

    public function test_cleanup_serializes_with_another_cleanup_for_the_same_organization(): void
    {
        $organization = Organization::factory()->create();
        $this->rows($organization->id, (string) Str::uuid(), 2, now()->subDays(2));
        $lease = Cache::lock('ai-rag-projection-retention:'.$organization->id, 120);
        $this->assertTrue($lease->get());
        try {
            $this->assertSame(['deleted' => 0, 'locked' => true], $this->projection()->pruneOrganization($organization->id));
            $this->assertSame(2, RagExpectedSource::query()->where('organization_id', $organization->id)->count());
        } finally {
            $lease->release();
        }
        $this->assertSame(2, $this->projection()->pruneOrganization($organization->id)['deleted']);
    }

    public function test_cleanup_removes_inactive_generations_after_the_build_safety_window(): void
    {
        $organization = Organization::factory()->create();
        $obsolete = (string) Str::uuid();
        $this->rows($organization->id, $obsolete, 2, now()->subHours(4));

        $this->assertSame(2, $this->projection()->pruneOrganization($organization->id)['deleted']);
        $this->assertSame(0, RagExpectedSource::query()->where('generation', $obsolete)->count());
    }

    public function test_cleanup_keeps_the_exact_safety_boundary_and_deletes_only_older_rows(): void
    {
        $this->travelTo(now()->startOfSecond());
        $organization = Organization::factory()->create();
        $boundary = (string) Str::uuid();
        $expired = (string) Str::uuid();
        $this->rows($organization->id, $boundary, 2, now()->subHours(3));
        $this->rows($organization->id, $expired, 2, now()->subHours(3)->subSecond());

        $this->assertSame(2, $this->projection()->pruneOrganization($organization->id)['deleted']);
        $this->assertSame(2, RagExpectedSource::query()->where('generation', $boundary)->count());
        $this->assertSame(0, RagExpectedSource::query()->where('generation', $expired)->count());
    }

    public function test_cleanup_deletes_only_bounded_batches_and_continues_on_the_next_invocation(): void
    {
        $organization = Organization::factory()->create();
        $this->rows($organization->id, (string) Str::uuid(), 1010, now()->subDays(2));
        $deleteBatchSizes = [];
        DB::listen(static function ($event) use (&$deleteBatchSizes): void {
            if (str_starts_with(strtolower($event->sql), 'delete') && str_contains($event->sql, 'ai_rag_expected_sources')) {
                $deleteBatchSizes[] = count($event->bindings) - 1;
            }
        });

        $this->assertSame(1005, $this->projection()->pruneOrganization($organization->id, 1005)['deleted']);
        $this->assertSame([1000, 5], $deleteBatchSizes);
        $this->assertSame(5, RagExpectedSource::query()->where('organization_id', $organization->id)->count());
        $this->assertSame(5, $this->projection()->pruneOrganization($organization->id)['deleted']);
    }

    public function test_discard_is_scoped_to_the_unpublished_generation_and_respects_its_budget(): void
    {
        $organization = Organization::factory()->create();
        $foreign = Organization::factory()->create();
        $generation = (string) Str::uuid();
        $active = (string) Str::uuid();
        $this->rows($organization->id, $generation, 3, now());
        $this->rows($organization->id, $active, 2, now());
        $this->rows($foreign->id, $generation, 2, now());

        $this->assertSame(2, $this->projection()->discard($organization->id, $generation, 2));
        $this->assertSame(1, $this->projection()->discard($organization->id, $generation));
        $this->assertSame(0, $this->projection()->discard($organization->id, $generation));
        $this->assertSame(2, RagExpectedSource::query()->where('generation', $active)->count());
        $this->assertSame(2, RagExpectedSource::query()->where('organization_id', $foreign->id)->count());
    }

    public function test_known_failed_generation_continues_before_grace_but_fresh_unknown_staging_is_preserved(): void
    {
        $organization = Organization::factory()->create();
        $failed = (string) Str::uuid();
        $fresh = (string) Str::uuid();
        $this->rows($organization->id, $failed, 5, now());
        $this->rows($organization->id, $fresh, 2, now());
        $this->assertSame(1, $this->projection()->discard($organization->id, $failed, 1));

        $this->assertSame(2, $this->projection()->pruneOrganization($organization->id, 2)['deleted']);
        $this->assertSame(2, $this->projection()->pruneOrganization($organization->id)['deleted']);
        $this->assertSame(0, $this->projection()->pruneOrganization($organization->id)['deleted']);
        $this->assertSame(2, RagExpectedSource::query()->where('generation', $fresh)->count());
        $this->assertFalse(Cache::has('ai-rag-coverage-discard-generations:'.$organization->id));
    }

    public function test_discard_pages_through_a_generation_and_resumes_after_its_row_budget(): void
    {
        $organization = Organization::factory()->create();
        $generation = (string) Str::uuid();
        $this->rows($organization->id, $generation, 1010, now());
        $deleteBatchSizes = [];
        DB::listen(static function ($event) use (&$deleteBatchSizes): void {
            if (str_starts_with(strtolower($event->sql), 'delete') && str_contains($event->sql, 'ai_rag_expected_sources')) {
                $deleteBatchSizes[] = count($event->bindings) - 1;
            }
        });

        $this->assertSame(1005, $this->projection()->discard($organization->id, $generation, 1005));
        $this->assertSame([1000, 5], $deleteBatchSizes);
        $this->assertSame(5, RagExpectedSource::query()->where('generation', $generation)->count());
        $this->assertSame(5, $this->projection()->discard($organization->id, $generation));
        $this->assertSame(0, RagExpectedSource::query()->where('generation', $generation)->count());
    }

    public function test_discard_keeps_neighbor_uuid_generations_and_identical_foreign_identities(): void
    {
        $organization = Organization::factory()->create();
        $foreign = Organization::factory()->create();
        $previous = 'a0000000-0000-0000-0000-000000000001';
        $target = 'a0000000-0000-0000-0000-000000000002';
        $next = 'a0000000-0000-0000-0000-000000000003';
        foreach ([$previous, $target, $next] as $generation) {
            $this->rows($organization->id, $generation, 3, now());
        }
        $this->rows($foreign->id, $target, 3, now());

        $this->assertSame(1, $this->projection()->discard($organization->id, $target, 1));
        $this->assertSame(2, $this->projection()->discard($organization->id, $target));
        $this->assertSame(0, RagExpectedSource::query()->where('organization_id', $organization->id)->where('generation', $target)->count());
        $this->assertSame(3, RagExpectedSource::query()->where('generation', $previous)->count());
        $this->assertSame(3, RagExpectedSource::query()->where('generation', $next)->count());
        $this->assertSame(3, RagExpectedSource::query()->where('organization_id', $foreign->id)->where('generation', $target)->count());
    }

    public function test_discard_does_not_mark_or_delete_the_cached_active_generation(): void
    {
        $organization = Organization::factory()->create();
        $active = (string) Str::uuid();
        $this->rows($organization->id, $active, 2, now());
        $revision = (int) Cache::get('ai-rag-coverage-revision:'.$organization->id, 0);
        $key = 'ai-rag-coverage:'.$organization->id.':0:*:'.$revision;
        Cache::put($key, ['projection_generation' => $active], 300);
        $this->assertSame($active, Cache::get($key)['projection_generation'] ?? null);

        $this->assertSame(0, $this->projection()->discard($organization->id, $active));
        $this->assertSame(2, RagExpectedSource::query()->where('generation', $active)->count());
        $this->assertFalse(Cache::has('ai-rag-coverage-discard-generations:'.$organization->id));
    }

    public function test_command_bounds_rows_and_advances_its_organization_cursor_between_invocations(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $this->rows($first->id, (string) Str::uuid(), 2, now()->subDays(2));
        $this->rows($second->id, (string) Str::uuid(), 2, now()->subDays(2));

        $this->artisan('ai-assistant:prune-rag-projections', ['--max-rows' => 1])->assertSuccessful();
        $this->assertSame(1, RagExpectedSource::query()->where('organization_id', $first->id)->count());
        $this->assertSame(2, RagExpectedSource::query()->where('organization_id', $second->id)->count());
        $this->artisan('ai-assistant:prune-rag-projections', ['--max-rows' => 1])->assertSuccessful();
        $this->assertSame(1, RagExpectedSource::query()->where('organization_id', $second->id)->count());
        $this->assertSame($second->id, Cache::get('ai-rag-projection-retention:organization-cursor'));
    }

    public function test_command_wraps_its_cursor_and_processes_backlog_in_the_same_invocation(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $this->rows($first->id, (string) Str::uuid(), 2, now()->subHours(4));
        $this->rows($second->id, (string) Str::uuid(), 2, now()->subHours(4));
        Cache::forever('ai-rag-projection-retention:organization-cursor', $second->id);

        $this->artisan('ai-assistant:prune-rag-projections', ['--max-rows' => 1])->assertSuccessful();
        $this->assertSame(1, RagExpectedSource::query()->where('organization_id', $first->id)->count());
        $this->assertSame(2, RagExpectedSource::query()->where('organization_id', $second->id)->count());
        $this->artisan('ai-assistant:prune-rag-projections', ['--max-rows' => 1])->assertSuccessful();
        $this->assertSame(1, RagExpectedSource::query()->where('organization_id', $second->id)->count());
    }

    public function test_command_accepts_a_larger_bounded_budget_and_rejects_an_unbounded_one(): void
    {
        $organization = Organization::factory()->create();
        $generation = (string) Str::uuid();
        $this->rows($organization->id, $generation, 2, now()->subHours(4));
        $this->artisan('ai-assistant:prune-rag-projections', [
            '--organization-id' => $organization->id, '--max-rows' => 1000001,
        ])->assertExitCode(2);
        $this->assertSame(2, RagExpectedSource::query()->where('generation', $generation)->count());

        $this->artisan('ai-assistant:prune-rag-projections', [
            '--organization-id' => $organization->id, '--max-rows' => 500000,
        ])->assertSuccessful();
        $this->assertSame(0, RagExpectedSource::query()->where('generation', $generation)->count());
    }

    private function projection(): RagExpectedSourceProjection
    {
        return new RagExpectedSourceProjection(Mockery::mock(RagIndexer::class));
    }

    private function rows(int $organizationId, string $generation, int $count, mixed $createdAt): void
    {
        $rows = [];
        for ($id = 0; $id < $count; $id++) {
            $rows[] = ['organization_id' => $organizationId, 'generation' => $generation,
                'identity_project_id' => 0, 'identity_part_key' => '', 'source_type' => 'project',
                'entity_type' => 'project', 'entity_id' => (string) $id, 'checksum' => hash('sha256', (string) $id),
                'pending_since' => $createdAt, 'created_at' => $createdAt, 'updated_at' => $createdAt];
        }
        DB::table('ai_rag_expected_sources')->insert($rows);
    }
}
