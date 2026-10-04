<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\Models\Project;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantAclParentQueryPlanTest extends TestCase
{
    public function test_source_counts_keep_a_bounded_plan_when_a_parent_catalog_exceeds_the_hash_memory_limit(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug'));
        $dataset = DB::table('estimate_dataset_versions')->insertGetId([
            'source_type' => 'fsnb_2022', 'version_key' => 'acl-source-plan', 'bucket' => 'testing', 'prefix' => 'resources',
            'status' => 'parsed', 'finished_at' => now(), 'rows_imported' => 20000,
        ]);
        $collection = DB::table('estimate_norm_collections')->insertGetId([
            'dataset_version_id' => $dataset, 'code' => 'visible', 'name' => 'Visible', 'norm_type' => 'gesn', 'source_file' => 'testing.xml',
        ]);
        DB::statement("INSERT INTO construction_resources (dataset_version_id, ksr_code, name, resource_type) SELECT ?, 'Resource ' || n, 'Resource ' || n, 'material' FROM generate_series(1, 20000) n", [$dataset]);
        DB::statement("INSERT INTO ai_rag_sources (organization_id, source_type, entity_type, entity_id, title, checksum) SELECT ?, 'organization_reporting', 'approved_construction_resource', id::text, name, md5(id::text) FROM construction_resources WHERE dataset_version_id = ?", [$fixture->organization->id, $dataset]);
        RagSource::query()->create([
            'organization_id' => $fixture->organization->id, 'source_type' => 'organization_reporting',
            'entity_type' => 'approved_estimate_norm_collection', 'entity_id' => (string) $collection, 'title' => 'Collection', 'checksum' => hash('sha256', 'collection'),
        ]);
        DB::statement('ANALYZE construction_resources');
        DB::statement('ANALYZE estimate_dataset_versions');
        DB::statement('ANALYZE estimate_norm_collections');
        DB::statement('ANALYZE ai_rag_sources');
        DB::statement("SET LOCAL work_mem = '64kB'");
        $policy = app(AssistantDataAccessPolicy::class);
        $policy->withCurrentChecks($fixture->owner, $fixture->organization->id, function () use ($policy, $fixture): void {
            $policy->prefetchEntitySchemaMetadata();
            $query = $policy->aggregateSourceIdentities(RagSource::query(), $fixture->owner, $fixture->organization->id,
                ['ai_rag_sources.id'], fn ($visible) => DB::query()->fromSub($visible, 'visible_sources')->selectRaw('COUNT(*) AS total'));
            self::assertNotNull($query);
            self::assertStringNotContainsString('"construction_resources"."name"', $query->toSql());
            $plan = json_decode(DB::select('EXPLAIN (FORMAT JSON) '.$query->toSql(), $query->getBindings())[0]->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR)[0];
            self::assertLessThan(100000, $plan['Plan']['Total Cost']);
            DB::statement('SET LOCAL statement_timeout = 10000');
            $rows = $query->get();
            self::assertCount(1, $rows);
            self::assertSame(20001, (int) $rows[0]->total);
            $project = Project::withoutEvents(fn () => Project::factory()->create([
                'organization_id' => $fixture->organization->id, 'is_archived' => false,
            ]));
            DB::statement("INSERT INTO ai_rag_sources (organization_id, source_type, entity_type, entity_id, title, checksum, identity_part_key, metadata) SELECT ?, 'project', 'project', ?::text, 'Project part ' || n, md5('project-part-' || n), n::text, jsonb_build_object('unit_id', n) FROM generate_series(1, 2) n", [$fixture->organization->id, $project->id]);
            $batches = $policy->aggregateSourceIdentityBatches(RagSource::query(), $fixture->owner, $fixture->organization->id,
                ['ai_rag_sources.id'], fn ($visible) => DB::query()->fromSub($visible, 'visible_sources')->selectRaw('COUNT(*) AS total'));
            self::assertCount(2, $batches);
            $counts = array_map(static fn ($batch): int => (int) $batch->first()->total, $batches);
            sort($counts);
            self::assertSame([2, 20001], $counts);
            Project::withoutEvents(fn () => $project->delete());
            $counts = array_map(static fn ($batch): int => (int) $batch->first()->total, $batches);
            sort($counts);
            self::assertSame([0, 20001], $counts);
        }, fresh: true);
    }

    public function test_norm_parent_matches_use_a_bounded_plan_and_reject_a_section_from_another_collection(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug'));
        $dataset = DB::table('estimate_dataset_versions')->insertGetId([
            'source_type' => 'fsnb_2022', 'version_key' => 'acl-parent-plan', 'bucket' => 'testing', 'prefix' => 'norms',
            'status' => 'parsed', 'finished_at' => now(), 'rows_imported' => 10000,
        ]);
        $collections = [];
        foreach (['visible', 'other'] as $code) {
            $collections[] = DB::table('estimate_norm_collections')->insertGetId([
                'dataset_version_id' => $dataset, 'code' => $code, 'name' => $code, 'norm_type' => 'gesn', 'source_file' => 'testing.xml',
            ]);
        }
        DB::statement("INSERT INTO estimate_norm_sections (collection_id, name, path) SELECT ?, 'Section ' || n, n::text FROM generate_series(1, 10000) n", [$collections[0]]);
        DB::statement("INSERT INTO estimate_norms (collection_id, section_id, code, name, unit) SELECT collection_id, id, 'Norm ' || id, 'Norm ' || id, 'm2' FROM estimate_norm_sections WHERE collection_id = ?", [$collections[0]]);
        $otherSection = DB::table('estimate_norm_sections')->insertGetId(['collection_id' => $collections[1], 'name' => 'Other', 'path' => 'other']);
        $invalid = DB::table('estimate_norms')->insertGetId(['collection_id' => $collections[0], 'section_id' => $otherSection, 'code' => 'invalid', 'name' => 'Invalid', 'unit' => 'm2']);
        $withoutSection = DB::table('estimate_norms')->insertGetId(['collection_id' => $collections[0], 'code' => 'without-section', 'name' => 'Without section', 'unit' => 'm2']);
        DB::statement("INSERT INTO ai_rag_sources (organization_id, source_type, entity_type, entity_id, title, checksum) SELECT ?, 'organization_reporting', 'approved_estimate_norm', id::text, name, md5(id::text) FROM estimate_norms WHERE collection_id = ?", [$fixture->organization->id, $collections[0]]);
        DB::statement('ANALYZE estimate_norm_sections');
        DB::statement('ANALYZE estimate_norms');
        $policy = app(AssistantDataAccessPolicy::class);
        $policy->withCurrentChecks($fixture->owner, $fixture->organization->id, function () use ($policy, $fixture, $invalid, $withoutSection): void {
            $policy->prefetchEntitySchemaMetadata();
            $query = $policy->entityQuery($fixture->owner, $fixture->organization->id, 'approved_estimate_norm');
            self::assertNotNull($query);
            $plan = json_decode(DB::select('EXPLAIN (FORMAT JSON) '.$query->toSql(), $query->getBindings())[0]->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR)[0];
            self::assertLessThan(100000, $plan['Plan']['Total Cost']);
            self::assertSame(10001, (clone $query)->count());
            self::assertFalse((clone $query)->where('estimate_norms.id', $invalid)->exists());
            self::assertTrue((clone $query)->where('estimate_norms.id', $withoutSection)->exists());
            $aggregate = $policy->aggregateSourceIdentities(RagSource::query(), $fixture->owner, $fixture->organization->id,
                ['ai_rag_sources.id'], fn ($visible) => DB::query()->fromSub($visible, 'visible_sources')->selectRaw('COUNT(*) AS total'));
            self::assertNotNull($aggregate);
            self::assertSame(10001, (int) $aggregate->first()->total);
        }, fresh: true);
    }

    public function test_compact_aggregate_validates_every_declared_identity_without_opening_missing_entities(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug'));
        foreach (AssistantDataAccessPolicy::entityDefinitions() as $type => $definition) {
            RagSource::query()->create([
                'organization_id' => $fixture->organization->id, 'source_type' => $definition[0],
                'entity_type' => $type, 'entity_id' => '0', 'title' => $type, 'checksum' => hash('sha256', $type),
                'metadata' => ['assistant_public_schema_revision' => \App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantFinanceTenderSourceSchema::revision($type)],
            ]);
        }
        $policy = app(AssistantDataAccessPolicy::class);
        $policy->withCurrentChecks($fixture->owner, $fixture->organization->id, function () use ($policy, $fixture): void {
            $policy->prefetchEntitySchemaMetadata();
            $query = $policy->aggregateSourceIdentities(RagSource::query(), $fixture->owner, $fixture->organization->id,
                ['ai_rag_sources.id'], fn ($visible) => DB::query()->fromSub($visible, 'visible_sources')->selectRaw('COUNT(*) AS total'));
            self::assertNotNull($query);
            self::assertSame(0, (int) $query->first()->total);
        }, fresh: true);
    }
}
