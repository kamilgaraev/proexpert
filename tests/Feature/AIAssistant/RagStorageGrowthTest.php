<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantExtendedDomainRegistry;
use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantOrganizationReportingMetadata as Metadata;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\CoreBusinessMoneyRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\OrganizationReportingRagSource;
use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotEpoch;
use App\Models\Organization;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Mockery;
use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\AssistantStoragePruner;

final class RagStorageGrowthTest extends TestCase
{
    private array $organizations = [];

    public function beginDatabaseTransaction(): void
    {
        self::assertMatchesRegularExpression('/^most_phpunit_[a-z0-9]+_testing$/i', DB::connection()->getDatabaseName());
    }

    protected function tearDown(): void
    {
        DB::table('organizations')->whereIn('id', $this->organizations)->delete();
        parent::tearDown();
    }

    public function test_shared_catalogs_remain_declared_for_live_access_without_organization_embeddings(): void
    {
        $source = new OrganizationReportingRagSource;
        foreach (Metadata::records() as $type => $record) {
            if (! in_array($record[2], ['normative_lookup', 'normative_prices'], true)) { continue; }
            self::assertSame('live_only', AssistantExtendedDomainRegistry::retrievalMode($type), $type);
            self::assertStringContainsString("'".$type."'", AssistantStoragePruner::CATALOG_PREDICATE);
            self::assertArrayNotHasKey($type, $source->entities(), $type);
            self::assertSame([], [...$source->collectEntity(1, $type, 1)], $type);
            self::assertArrayHasKey($type, Metadata::entityDefinitions());
            self::assertNotEmpty(Metadata::entityPermissions()[$type]);
        }
        self::assertSame('live_only', AssistantExtendedDomainRegistry::retrievalMode('core_normative_resource'));
        self::assertArrayNotHasKey('core_normative_resource', (new CoreBusinessMoneyRagSource)->entities());
        self::assertArrayHasKey('mdm_record', $source->entities());
    }

    public function test_scheduled_cleanup_drains_multiple_ledger_batches_without_a_status_request(): void
    {
        Cache::flush();
        $table = AssistantStatusSnapshotEpoch::CHANGE_TABLE;
        DB::table($table)->delete();
        DB::insert('INSERT INTO public.'.$table.' (xid, relation_oid, created_at) '
            .'SELECT pg_current_xact_id(), n::oid, statement_timestamp() - make_interval(secs => CASE '
            .'WHEN n <= 20003 THEN 3600 ELSE 0 END) FROM generate_series(1, 20004) n');
        $generation = (int) DB::table(AssistantStatusSnapshotEpoch::CONTROL_TABLE)->value('gc_generation');

        $this->artisan('ai-assistant:prune-storage', ['--max-batches' => 1])->assertSuccessful();
        self::assertSame(10003, DB::table($table)->whereRaw("created_at < statement_timestamp() - interval '10 minutes'")->count());
        $this->artisan('ai-assistant:prune-storage', ['--max-batches' => 4])->assertSuccessful();
        self::assertSame([20004], array_map('intval', DB::table($table)->pluck('relation_oid')->all()));
        self::assertSame($generation + 3, (int) DB::table(AssistantStatusSnapshotEpoch::CONTROL_TABLE)->value('gc_generation'));
    }

    public function test_direct_indexing_cannot_recreate_a_live_catalog_or_call_a_paid_provider(): void
    {
        $provider = Mockery::mock(RagEmbeddingProviderInterface::class);
        $provider->shouldNotReceive('embed');
        $indexer = new RagIndexer($provider, new RagSourceRegistry([]));
        $before = DB::table('ai_rag_sources')->count();
        foreach (['core_normative_resource', 'approved_estimate_norm', 'approved_estimate_resource_price'] as $type) {
            $indexer->indexChunk(new RagChunkData(1, null, 'organization_reporting', $type, 'blocked', 'Catalog', 'Old catalog'));
        }
        self::assertSame($before, DB::table('ai_rag_sources')->count());
    }

    public function test_catalog_cleanup_is_bounded_and_preserves_private_sources_and_unrelated_identities(): void
    {
        Cache::flush();
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $other = Organization::withoutEvents(fn () => Organization::factory()->create());
        $this->organizations = [$organization->id, $other->id];
        $generation = (string) Str::uuid();
        $rows = [];
        for ($n = 0; $n < 1002; $n++) {
            $org = $n % 2 === 0 ? $organization->id : $other->id;
            $rows[] = ['organization_id' => $org, 'source_type' => 'core_business_money',
                'entity_type' => 'core_normative_resource', 'entity_id' => (string) $n, 'title' => 'Catalog', 'checksum' => hash('sha256', (string) $n)];
        }
        DB::table('ai_rag_sources')->insert($rows);
        $sources = DB::table('ai_rag_sources')->whereIn('organization_id', $this->organizations)->get(['id', 'organization_id']);
        $ids = $sources->pluck('id');
        $chunks = [];
        foreach ($sources as $source) {
            $chunks[] = ['source_id' => $source->id, 'organization_id' => $source->organization_id, 'chunk_index' => 0, 'content' => 'Catalog', 'content_hash' => hash('sha256', 'Catalog')];
        }
        DB::table('ai_rag_chunks')->insert($chunks);
        $chunkIds = DB::table('ai_rag_chunks')->whereIn('source_id', $ids)->pluck('id');
        DB::table('ai_rag_expected_sources')->insert(['organization_id' => $organization->id, 'generation' => $generation,
            'source_type' => 'core_business_money', 'entity_type' => 'core_normative_resource', 'entity_id' => '0',
            'checksum' => hash('sha256', '0'), 'pending_since' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ai_rag_sources')->insert([
            ['organization_id' => $organization->id, 'source_type' => 'project', 'entity_type' => 'project', 'entity_id' => '1', 'title' => 'Private', 'checksum' => hash('sha256', 'private')],
            ['organization_id' => $other->id, 'source_type' => 'unrelated', 'entity_type' => 'core_normative_resource', 'entity_id' => '1', 'title' => 'Keep', 'checksum' => hash('sha256', 'keep')],
        ]);
        $coverageUpdates = 0;
        DB::listen(static function ($query) use (&$coverageUpdates): void {
            if (str_starts_with($query->sql, 'update "ai_rag_coverage_states"')) { $coverageUpdates++; }
        });

        $this->artisan('ai-assistant:prune-storage', ['--max-batches' => 1])->assertSuccessful();
        self::assertSame(1, $coverageUpdates);
        self::assertSame(2, DB::table('ai_rag_sources')->whereIn('id', $ids)->count());
        self::assertSame(2, DB::table('ai_rag_chunks')->whereIn('source_id', $ids)->count());
        self::assertSame(0, DB::table('ai_rag_expected_sources')->where('generation', $generation)->count());
        $this->artisan('ai-assistant:prune-storage', ['--max-batches' => 4])->assertSuccessful();
        self::assertSame(0, DB::table('ai_rag_sources')->whereIn('id', $ids)->count());
        self::assertSame(0, DB::table('ai_rag_status_chunks')->whereIn('id', $chunkIds)->count());
        self::assertSame(0, DB::table('ai_rag_status_sources')->whereIn('id', $ids)->count());
        self::assertSame(2, DB::table('ai_rag_sources')->whereIn('organization_id', $this->organizations)->count());
        self::assertGreaterThan(0, (int) DB::table('ai_rag_coverage_states')->where('organization_id', $organization->id)->value('revision'));
    }

    public function test_empty_catalog_cleanup_uses_partial_indexes_without_scanning_private_data(): void
    {
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $this->organizations = [$organization->id];
        DB::insert("INSERT INTO ai_rag_sources (organization_id, source_type, entity_type, entity_id, title, checksum) "
            ."SELECT ?, 'project', 'project', n::text, 'Private', repeat('a', 64) FROM generate_series(1, 10000) n", [$organization->id]);
        DB::insert("INSERT INTO ai_rag_expected_sources (organization_id, generation, source_type, entity_type, entity_id, checksum, pending_since, created_at, updated_at) "
            ."SELECT ?, ?::uuid, 'project', 'project', n::text, repeat('a', 64), now(), now(), now() FROM generate_series(1, 10000) n", [$organization->id, (string) Str::uuid()]);
        foreach (['ai_rag_sources' => 'ai_rag_retired_catalog_sources_idx', 'ai_rag_expected_sources' => 'ai_rag_retired_catalog_expected_idx'] as $table => $index) {
            DB::statement('ANALYZE '.$table);
            $plan = DB::selectOne('EXPLAIN (FORMAT JSON) SELECT id, organization_id FROM '.$table.' WHERE ('.AssistantStoragePruner::CATALOG_PREDICATE.') ORDER BY id LIMIT 1000 FOR UPDATE SKIP LOCKED');
            self::assertStringContainsString($index, $plan->{'QUERY PLAN'});
            self::assertStringNotContainsString('Seq Scan', $plan->{'QUERY PLAN'});
        }
    }
}
