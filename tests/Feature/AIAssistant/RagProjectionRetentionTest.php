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
        $this->rows($organization->id, $fresh, 2, now()->subHours(3));
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

    public function test_cleanup_skips_an_organization_while_full_refresh_holds_its_lock(): void
    {
        $organization = Organization::factory()->create();
        $this->rows($organization->id, (string) Str::uuid(), 2, now()->subDays(2));
        $lease = Cache::lock('ai-rag-coverage-projection:'.$organization->id, 7500);
        $this->assertTrue($lease->get());
        try {
            $this->assertSame(['deleted' => 0, 'locked' => true], $this->projection()->pruneOrganization($organization->id));
            $this->assertSame(2, RagExpectedSource::query()->where('organization_id', $organization->id)->count());
        } finally {
            $lease->release();
        }
        $this->assertSame(2, $this->projection()->pruneOrganization($organization->id)['deleted']);
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
