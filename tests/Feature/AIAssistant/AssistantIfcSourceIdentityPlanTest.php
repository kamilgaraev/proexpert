<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\RagExpectedSource;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagExpectedSourceProjection;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use App\BusinessModules\Features\DesignManagement\Models\DesignReviewRound;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\Support\RagTestEmbedding;
use Tests\TestCase;

final class AssistantIfcSourceIdentityPlanTest extends TestCase
{
    public static function identityProjectionCases(): array
    {
        return ['stored' => [false], 'expected' => [true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('identityProjectionCases')]
    public function test_sparse_ifc_sources_use_native_keys_and_keep_current_parent_access(bool $expectedProjection): void
    {
        $sourceClass = $expectedProjection ? RagExpectedSource::class : RagSource::class;
        $table = $expectedProjection ? 'ai_rag_expected_sources' : 'ai_rag_sources';
        $generation = (string) Str::uuid();
        $fixture = AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug'));
        $organizationId = $fixture->organization->id;
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $organizationId, 'is_archived' => false]));
        $otherProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $organizationId, 'is_archived' => false]));
        $package = DesignPackage::withoutEvents(fn () => DesignPackage::query()->create([
            'organization_id' => $organizationId, 'project_id' => $project->id, 'title' => 'Комплект', 'status' => 'draft',
        ]));
        $artifact = DesignArtifact::withoutEvents(fn () => DesignArtifact::query()->create([
            'organization_id' => $organizationId, 'project_id' => $project->id, 'package_id' => $package->id,
            'title' => 'Модель', 'artifact_type' => 'model',
        ]));
        $version = DesignArtifactVersion::withoutEvents(fn () => DesignArtifactVersion::query()->create([
            'organization_id' => $organizationId, 'project_id' => $project->id, 'artifact_id' => $artifact->id,
            'title' => 'Версия', 'version_number' => '1', 'source_file_path' => 'private.ifc',
            'source_original_name' => 'model.ifc', 'source_mime_type' => 'application/octet-stream', 'source_size_bytes' => 1,
        ]));
        $otherVersion = DesignArtifactVersion::withoutEvents(fn () => $version->replicate()->forceFill(['version_number' => '2']));
        $otherVersion->saveQuietly();
        $derivative = DesignModelDerivative::withoutEvents(fn () => DesignModelDerivative::query()->create([
            'organization_id' => $organizationId, 'project_id' => $project->id, 'version_id' => $version->id,
        ]));
        DB::statement('INSERT INTO design_ifc_model_elements (organization_id, project_id, version_id, derivative_id, express_id) SELECT ?, ?, ?, ?, n FROM generate_series(1, 20000) n',
            [$organizationId, $project->id, $version->id, $derivative->id]);
        $firstId = (int) DB::table('design_ifc_model_elements')->where('version_id', $version->id)->min('id');
        $keys = [$firstId, PHP_INT_MIN, -1, 0, PHP_INT_MAX];
        foreach (array_slice($keys, 1) as $index => $key) {
            DB::table('design_ifc_model_elements')->insert([
                'id' => $key, 'organization_id' => $organizationId, 'project_id' => $project->id,
                'version_id' => $version->id, 'derivative_id' => $derivative->id, 'express_id' => 20001 + $index,
            ]);
        }
        $allowed = [];
        $firstSource = null;
        $firstParts = [];
        foreach ($keys as $key) {
            $attributes = [
                'organization_id' => $organizationId, 'project_id' => $project->id, 'source_type' => 'design_additional',
                'entity_type' => 'design_ifc_model_element', 'entity_id' => (string) $key,
                'checksum' => hash('sha256', 'ifc-'.$key),
            ];
            $attributes += $expectedProjection
                ? ['generation' => $generation, 'identity_project_id' => $project->id, 'pending_since' => now()]
                : ['title' => 'IFC'];
            $source = $sourceClass::withoutEvents(fn () => $sourceClass::query()->create($attributes));
            $allowed[] = $source->id;
            if ($key === $firstId) {
                $firstSource = $source;
                $part = $source->replicate()->forceFill(['identity_part_key' => 'second-part']);
                $part->saveQuietly();
                $allowed[] = $part->id;
                $firstParts = [$source->id, $part->id];
            }
        }
        self::assertInstanceOf($sourceClass, $firstSource);
        foreach (['0'.$firstId, '+'.$firstId, ' '.$firstId, $firstId.' ', $firstId.'.0', 'invalid', '9223372036854775808', '-9223372036854775809', '٠'] as $invalid) {
            $firstSource->replicate()->forceFill(['entity_id' => $invalid])->saveQuietly();
        }
        $foreignOrganization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $firstSource->replicate()->forceFill(['organization_id' => $foreignOrganization->id])->saveQuietly();
        foreach (['design_ifc_model_elements', $table, 'ai_rag_status_sources'] as $analyzeTable) {
            DB::statement('ANALYZE '.$analyzeTable);
        }
        $policy = app(AssistantDataAccessPolicy::class);
        $build = function () use ($policy, $fixture, $organizationId, $expectedProjection, $table, $generation): array {
            $policy->prefetchEntitySchemaMetadata();

            if ($expectedProjection) {
                return $policy->aggregateExpectedSourceIdentityBatches(
                    RagExpectedSource::query()->where('generation', $generation), $fixture->owner, $organizationId,
                    [$table.'.id'], static fn ($visible) => DB::query()->fromSub($visible, 'visible')->select('visible.id'),
                );
            }

            return $policy->aggregateSourceIdentityBatches(
                RagSource::query()->from('ai_rag_status_sources as ai_rag_sources'), $fixture->owner, $organizationId,
                ['ai_rag_sources.id'], static fn ($visible) => DB::query()->fromSub($visible, 'visible')->select('visible.id'),
            );
        };
        $read = fn (): array => $policy->withCurrentChecks($fixture->owner, $organizationId, function () use ($build): array {
            $ids = array_merge(...array_map(static fn ($batch): array => $batch->get()->pluck('id')->all(), $build()));
            sort($ids);

            return $ids;
        }, fresh: true);
        sort($allowed);
        self::assertSame($allowed, $read());
        $policy->withCurrentChecks($fixture->owner, $organizationId, function () use ($build): void {
            $nativeScans = [];
            $collect = static function (array $node) use (&$collect, &$nativeScans): void {
                if (($node['Relation Name'] ?? null) === 'design_ifc_model_elements') {
                    $nativeScans[] = $node;
                }
                foreach ($node['Plans'] ?? [] as $child) {
                    $collect($child);
                }
            };
            foreach ($build() as $batch) {
                $row = DB::selectOne('EXPLAIN (ANALYZE, BUFFERS, TIMING FALSE, FORMAT JSON) '.$batch->toSql(), $batch->getBindings());
                $collect(json_decode($row->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR)[0]['Plan']);
            }
            self::assertNotEmpty($nativeScans);
            foreach ($nativeScans as $node) {
                self::assertNotSame('Seq Scan', $node['Node Type']);
                $inspected = (($node['Actual Rows'] ?? 0) + ($node['Rows Removed by Filter'] ?? 0)) * ($node['Actual Loops'] ?? 1);
                self::assertLessThanOrEqual(6, $inspected);
            }
        }, fresh: true);
        $derivative->updateQuietly(['version_id' => $otherVersion->id]);
        self::assertSame([], $read());
        DB::table('design_ifc_model_elements')->where('id', $firstId)->update(['derivative_id' => null]);
        sort($firstParts);
        self::assertSame($firstParts, $read());
        $derivative->updateQuietly(['version_id' => $version->id, 'project_id' => $otherProject->id]);
        self::assertSame($firstParts, $read());
        $derivative->updateQuietly(['project_id' => $project->id]);
        self::assertSame($allowed, $read());
        $version->updateQuietly(['project_id' => $otherProject->id]);
        self::assertSame([], $read());
        $version->updateQuietly(['project_id' => $project->id]);
        self::assertSame($allowed, $read());
        $fixture->owner->organizations()->updateExistingPivot($organizationId, ['is_active' => false]);
        self::assertSame([], $read());
        $fixture->owner->organizations()->updateExistingPivot($organizationId, ['is_active' => true]);
        $sparseCounterPlan = $expectedProjection ? $this->assertFullExpectedCounterPlan($policy, $fixture, $project, $generation, $allowed) : null;
        $extraColumns = $expectedProjection ? 'generation, identity_project_id, pending_since' : 'title';
        $extraValues = $expectedProjection ? 'CAST(? AS uuid), e.project_id, CURRENT_TIMESTAMP' : "'IFC'";
        $bindings = $expectedProjection ? [$generation, $organizationId, $project->id] : [$organizationId, $project->id];
        DB::statement('INSERT INTO '.$table.' (organization_id, project_id, source_type, entity_type, entity_id, checksum, '.$extraColumns.") SELECT e.organization_id, e.project_id, 'design_additional', 'design_ifc_model_element', e.id::text, md5(e.id::text), ".$extraValues.' FROM design_ifc_model_elements e WHERE e.organization_id = ? AND e.project_id = ? AND NOT EXISTS (SELECT 1 FROM '.$table." s WHERE s.organization_id = e.organization_id AND s.project_id = e.project_id AND s.source_type = 'design_additional' AND s.entity_type = 'design_ifc_model_element' AND s.entity_id = e.id::text)", $bindings);
        DB::statement('ANALYZE '.($expectedProjection ? $table : 'ai_rag_status_sources'));
        self::assertCount(20005, $read());
        if ($expectedProjection) {
            $this->assertFullExpectedCounterPlan($policy, $fixture, $project, $generation, $allowed, dense: true);
            self::assertNotNull($sparseCounterPlan);
            self::assertLessThanOrEqual(32, $sparseCounterPlan['source_visits'], 'A sparse expected IFC generation must not materialize unrelated stored identities.');
            self::assertLessThanOrEqual(128, $sparseCounterPlan['source_blocks']);
            self::assertLessThanOrEqual(32, $sparseCounterPlan['status_visits']);
            self::assertLessThanOrEqual(64, $sparseCounterPlan['expected_visits']);
        }
        $policy->withCurrentChecks($fixture->owner, $organizationId, function () use ($build): void {
            foreach ($build() as $batch) {
                self::assertStringNotContainsString('ARRAY_AGG', $batch->toSql());
            }
        }, fresh: true);
        if (! $expectedProjection) {
            $policy->withCurrentChecks($fixture->owner, $organizationId, function () use ($policy, $fixture, $organizationId): void {
                $batches = $policy->aggregateSourceIdentityBatches(
                    RagSource::query()->from('ai_rag_status_sources as ai_rag_sources'), $fixture->owner, $organizationId,
                    ['ai_rag_sources.id', 'ai_rag_sources.source_type', 'ai_rag_sources.project_id', 'ai_rag_sources.chunk_count', 'ai_rag_sources.indexed_chunk_count'],
                    static fn ($visible) => DB::query()->fromSub($visible, 'accessible_sources')
                        ->groupBy('accessible_sources.source_type')->selectRaw('accessible_sources.source_type, COUNT(*) AS stored_count, COALESCE(pg_catalog.SUM(accessible_sources.chunk_count), 0) AS chunk_count, COUNT(CASE WHEN accessible_sources.indexed_chunk_count > 0 THEN 1 END) AS indexed_count'),
                );
                self::assertCount(1, $batches);
                $counts = $batches[0]->first();
                self::assertSame(20005, (int) $counts->stored_count);
                self::assertSame(0, (int) $counts->chunk_count);
                self::assertSame(0, (int) $counts->indexed_count);
                $row = DB::selectOne('EXPLAIN (ANALYZE, BUFFERS, TIMING FALSE, FORMAT JSON) '.$batches[0]->toSql(), $batches[0]->getBindings());
                $scanned = 0;
                $collect = static function (array $node) use (&$collect, &$scanned): void {
                    if (($node['Relation Name'] ?? null) === 'ai_rag_status_sources') {
                        $scanned += (($node['Actual Rows'] ?? 0) + ($node['Rows Removed by Filter'] ?? 0)) * ($node['Actual Loops'] ?? 1);
                    }
                    foreach ($node['Plans'] ?? [] as $child) {
                        $collect($child);
                    }
                };
                $collect(json_decode($row->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR)[0]['Plan']);
                self::assertLessThanOrEqual(21000, $scanned, 'Counting a dense IFC corpus must not reread every source row to recover its counters.');
            }, fresh: true);
            $this->assertMixedCorpusCounterPlan($policy, $fixture, $project, $package);
        }
    }

    private function assertFullExpectedCounterPlan(AssistantDataAccessPolicy $policy, AssistantRealAuthorizationFixture $fixture, Project $project, string $generation, array $allowed, bool $dense = false): array
    {
        $organizationId = (int) $fixture->organization->id;
        if (! $dense) {
            DB::statement("INSERT INTO ai_rag_sources (organization_id, project_id, identity_project_id, source_type, entity_type, entity_id, checksum, title) SELECT organization_id, project_id, project_id, 'design_additional', 'design_ifc_model_element', id::text, md5(id::text), 'IFC' FROM design_ifc_model_elements WHERE organization_id = ? AND project_id = ?", [$organizationId, $project->id]);
        }
        $expected = RagExpectedSource::query()->where('organization_id', $organizationId)->where('generation', $generation)->whereIn('id', $allowed)->get();
        $first = null;
        foreach ($expected as $row) {
            $source = RagSource::query()->updateOrCreate($row->only(['organization_id', 'project_id', 'identity_project_id', 'identity_part_key', 'source_type', 'entity_type', 'entity_id']),
                ['title' => 'IFC', 'checksum' => $row->checksum]);
            $first ??= $source;
        }
        self::assertInstanceOf(RagSource::class, $first);
        if (! $dense) { DB::table('ai_rag_chunks')->insert([
            'organization_id' => $organizationId, 'project_id' => $project->id, 'source_id' => $first->id,
            'chunk_index' => 0, 'content' => 'IFC fixture', 'content_hash' => hash('sha256', 'IFC fixture'),
            'embedding' => '['.implode(',', RagTestEmbedding::fromLeadingValues([0.1, 0.2])).']',
        ]); }
        $actor = $fixture->addMember([
            'ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view'],
            'design-management' => ['design-management.view', 'design-management.models.view'],
        ]);
        $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        foreach (['design_ifc_model_elements', 'ai_rag_sources', 'ai_rag_status_sources', 'ai_rag_expected_sources'] as $table) { DB::statement('ANALYZE '.$table); }
        $projection = new RagExpectedSourceProjection(new RagIndexer(\Mockery::mock(RagEmbeddingProviderInterface::class), new RagSourceRegistry([])));
        $queries = [];
        $connection = DB::connection();
        $dispatcher = $connection->getEventDispatcher();
        self::assertNotNull($dispatcher);
        $connection->setEventDispatcher(clone $dispatcher);
        $connection->listen(static function (QueryExecuted $event) use (&$queries): void {
            if (str_contains($event->sql, 'expected.source_type, COUNT(*)')) { $queries[] = $event; }
        });
        $read = fn (): array => $policy->withCurrentChecks($actor, $organizationId,
            fn (): array => $projection->actorCounts($organizationId, $actor, $policy, ['design_additional'], $generation, collectProof: false), fresh: true);
        try {
            $counts = $read();
        } finally {
            $connection->setEventDispatcher($dispatcher);
        }
        self::assertCount(1, $queries);
        $expectedCount = $dense ? 20005 : 6;
        self::assertSame($expectedCount, (int) $counts['design_additional']->expected_count);
        self::assertSame(1, (int) $counts['design_additional']->indexed_count);
        $query = $queries[0];
        if ($dense) {
            $estimated = DB::selectOne('EXPLAIN (FORMAT JSON) '.$query->sql, $query->bindings);
            self::assertNotNull($estimated);
            $nodes = [];
            $walk = static function (array $node) use (&$walk, &$nodes): void {
                $nodes[] = ['node' => $node['Node Type'], 'join' => $node['Join Type'] ?? null, 'rows' => $node['Plan Rows']];
                foreach ($node['Plans'] ?? [] as $child) { $walk($child); }
            };
            $walk(json_decode($estimated->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR)[0]['Plan']);
            fwrite(STDERR, json_encode(['probe' => 'ifc_dense_estimated_plan', 'nodes' => $nodes], JSON_THROW_ON_ERROR).PHP_EOL);
        }
        $timeoutRow = DB::selectOne('SHOW statement_timeout');
        self::assertNotNull($timeoutRow);
        $previousTimeout = $timeoutRow->statement_timeout;
        try {
            $row = DB::transaction(static function () use ($query): object {
                DB::selectOne("SELECT pg_catalog.set_config('statement_timeout', ?, true)", ['10s']);

                $explained = DB::selectOne('EXPLAIN (ANALYZE, BUFFERS, TIMING FALSE, FORMAT JSON) '.$query->sql, $query->bindings);
                self::assertNotNull($explained);

                return $explained;
            });
        } finally {
            DB::selectOne("SELECT pg_catalog.set_config('statement_timeout', ?, true)", [$previousTimeout]);
        }
        $plan = json_decode($row->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR)[0];
        $relations = [];
        $sourceVisits = 0;
        $joinComparisonsMax = 0;
        $collect = static function (array $node) use (&$collect, &$relations, &$sourceVisits, &$joinComparisonsMax): void {
            if (($node['Node Type'] ?? null) === 'Nested Loop') {
                $joinComparisonsMax = max($joinComparisonsMax, (($node['Actual Rows'] ?? 0) + ($node['Rows Removed by Join Filter'] ?? 0)) * ($node['Actual Loops'] ?? 1));
            }
            if (in_array($node['Relation Name'] ?? null, ['design_ifc_model_elements', 'ai_rag_sources', 'ai_rag_status_sources', 'ai_rag_expected_sources'], true)) {
                $visits = (($node['Actual Rows'] ?? 0) + ($node['Rows Removed by Filter'] ?? 0)) * ($node['Actual Loops'] ?? 1);
                $relations[] = ['relation' => $node['Relation Name'], 'node' => $node['Node Type'], 'rows' => $node['Actual Rows'] ?? 0,
                    'loops' => $node['Actual Loops'] ?? 0, 'visits' => $visits, 'shared_hit_blocks' => $node['Shared Hit Blocks'] ?? 0,
                    'shared_read_blocks' => $node['Shared Read Blocks'] ?? 0, 'temp_read_blocks' => $node['Temp Read Blocks'] ?? 0,
                    'temp_written_blocks' => $node['Temp Written Blocks'] ?? 0];
                if ($node['Relation Name'] === 'ai_rag_sources') { $sourceVisits += $visits; }
            }
            foreach ($node['Plans'] ?? [] as $child) { $collect($child); }
        };
        $collect($plan['Plan']);
        fwrite(STDERR, json_encode(['probe' => $dense ? 'ifc_dense_expected_counts' : 'ifc_full_expected_counts', 'planning_ms' => $plan['Planning Time'], 'execution_ms' => $plan['Execution Time'],
            'sql_bytes' => strlen($query->sql), 'bindings' => count($query->bindings), 'join_comparisons_max' => $joinComparisonsMax, 'relations' => $relations], JSON_THROW_ON_ERROR).PHP_EOL);
        $actor->assignedProjects()->updateExistingPivot($project->id, ['is_active' => false]);
        self::assertSame([], $read());
        $actor->assignedProjects()->updateExistingPivot($project->id, ['is_active' => true]);
        $restored = $read();
        self::assertSame($expectedCount, (int) $restored['design_additional']->expected_count);
        self::assertSame(1, (int) $restored['design_additional']->indexed_count);
        $sourceBlocks = array_sum(array_map(static fn (array $node): int => $node['relation'] === 'ai_rag_sources' ? $node['shared_hit_blocks'] + $node['shared_read_blocks'] : 0, $relations));
        $statusVisits = array_sum(array_map(static fn (array $node): int => $node['relation'] === 'ai_rag_status_sources' ? $node['visits'] : 0, $relations));
        $expectedVisits = array_sum(array_map(static fn (array $node): int => $node['relation'] === 'ai_rag_expected_sources' ? $node['visits'] : 0, $relations));
        if ($dense) {
            self::assertLessThanOrEqual(21000, $sourceVisits, 'Dense IFC counts must not repeatedly scan stored identities.');
            self::assertLessThanOrEqual(100000, $sourceBlocks);
            self::assertLessThanOrEqual(21000, $statusVisits);
            self::assertLessThanOrEqual(100000, $expectedVisits);
            self::assertLessThanOrEqual(250000, $joinComparisonsMax, 'Dense IFC counts must not compare every expected identity with every stored source.');
        }
        if (! $dense) {
            $checksum = $first->checksum;
            $first->update(['checksum' => hash('sha256', 'stale indexed IFC')]);
            self::assertSame(0, (int) $read()['design_additional']->indexed_count);
            $first->update(['checksum' => $checksum]);
            self::assertSame(1, (int) $read()['design_additional']->indexed_count);
            $older = $expected->first()->replicate()->forceFill(['generation' => (string) Str::uuid(), 'checksum' => hash('sha256', 'other generation')]);
            $older->saveQuietly();
            $other = $policy->withCurrentChecks($actor, $organizationId,
                fn (): array => $projection->actorCounts($organizationId, $actor, $policy, ['design_additional'], $older->generation, collectProof: false), fresh: true);
            self::assertSame(1, (int) $other['design_additional']->expected_count);
            self::assertSame(0, (int) $other['design_additional']->indexed_count);
            self::assertSame(6, (int) $read()['design_additional']->expected_count);
            $status = (array) DB::table('ai_rag_status_sources')->where('id', $first->id)->first();
            DB::table('ai_rag_status_sources')->where('id', $first->id)->delete();
            $missing = $read();
            self::assertSame(6, (int) $missing['design_additional']->expected_count);
            self::assertSame(0, (int) $missing['design_additional']->indexed_count);
            self::assertNotNull($missing['design_additional']->pending_since);
            DB::table('ai_rag_status_sources')->insert($status);
            self::assertSame(1, (int) $read()['design_additional']->indexed_count);
            $nullExpected = $expected->first()->replicate()->forceFill(['project_id' => null, 'identity_project_id' => 0, 'identity_part_key' => 'null-project']);
            $nullExpected->saveQuietly();
            $nullSource = RagSource::query()->create($nullExpected->only(['organization_id', 'project_id', 'identity_project_id', 'identity_part_key', 'source_type', 'entity_type', 'entity_id', 'checksum']) + ['title' => 'IFC null project']);
            DB::table('ai_rag_chunks')->insert([
                'organization_id' => $organizationId, 'project_id' => null, 'source_id' => $nullSource->id,
                'chunk_index' => 0, 'content' => 'IFC null fixture', 'content_hash' => hash('sha256', 'IFC null fixture'),
                'embedding' => '['.implode(',', RagTestEmbedding::fromLeadingValues([0.1, 0.2])).']',
            ]);
            $nullCounts = $read();
            self::assertSame(7, (int) $nullCounts['design_additional']->expected_count);
            self::assertSame(2, (int) $nullCounts['design_additional']->indexed_count);
            $proof = null;
            $full = $policy->withCurrentChecks($actor, $organizationId,
                function () use ($projection, $organizationId, $actor, $policy, $generation, &$proof): array {
                    return $projection->actorCounts($organizationId, $actor, $policy, ['design_additional'], $generation, proof: $proof);
                }, fresh: true);
            self::assertCount(7, $proof);
            self::assertArrayHasKey($nullExpected->id, $proof);
            self::assertSame((int) $full['design_additional']->expected_count, (int) $nullCounts['design_additional']->expected_count);
            self::assertSame((int) $full['design_additional']->indexed_count, (int) $nullCounts['design_additional']->indexed_count);
            self::assertSame((string) $full['design_additional']->pending_since, (string) $nullCounts['design_additional']->pending_since);
            $nullExpected->delete();
            $nullSource->delete();
        }

        return ['source_visits' => $sourceVisits, 'source_blocks' => $sourceBlocks, 'status_visits' => $statusVisits, 'expected_visits' => $expectedVisits];
    }

    private function assertMixedCorpusCounterPlan(AssistantDataAccessPolicy $policy, AssistantRealAuthorizationFixture $fixture, Project $project, DesignPackage $package): void
    {
        $organizationId = (int) $fixture->organization->id;
        $section = DesignPackageSection::withoutEvents(fn () => DesignPackageSection::query()->create([
            'organization_id' => $organizationId, 'project_id' => $project->id, 'package_id' => $package->id,
            'code' => 'AR', 'title' => 'Section', 'project_stage' => $package->project_stage,
        ]));
        $round = DesignReviewRound::withoutEvents(fn () => DesignReviewRound::query()->create([
            'organization_id' => $organizationId, 'project_id' => $project->id, 'package_id' => $package->id, 'round_number' => 1,
        ]));
        foreach (['design_package_section' => $section->id, 'design_review_round' => $round->id] as $type => $id) {
            RagSource::withoutEvents(fn () => RagSource::query()->create([
                'organization_id' => $organizationId, 'project_id' => $project->id, 'source_type' => 'design_additional',
                'entity_type' => $type, 'entity_id' => (string) $id, 'title' => 'Mixed source', 'checksum' => hash('sha256', $type),
            ]));
        }
        DB::statement('ANALYZE ai_rag_status_sources');
        DB::statement("SET LOCAL work_mem = '64kB'");
        $policy->withCurrentChecks($fixture->owner, $organizationId, function () use ($policy, $fixture, $organizationId, $package): void {
            $batches = $policy->aggregateSourceIdentityBatches(
                RagSource::query()->from('ai_rag_status_sources as ai_rag_sources'), $fixture->owner, $organizationId,
                ['ai_rag_sources.id', 'ai_rag_sources.source_type', 'ai_rag_sources.project_id', 'ai_rag_sources.chunk_count', 'ai_rag_sources.indexed_chunk_count'],
                static fn ($visible) => DB::query()->fromSub($visible, 'accessible_sources')->groupBy('accessible_sources.source_type')
                    ->selectRaw('accessible_sources.source_type, COUNT(*) AS stored_count, COALESCE(pg_catalog.SUM(accessible_sources.chunk_count), 0) AS chunk_count, COUNT(CASE WHEN accessible_sources.indexed_chunk_count > 0 THEN 1 END) AS indexed_count'),
            );
            self::assertCount(1, $batches);
            $counts = $batches[0]->first();
            self::assertSame(20007, (int) $counts->stored_count);
            self::assertSame(0, (int) $counts->chunk_count);
            self::assertSame(0, (int) $counts->indexed_count);
            $row = DB::selectOne('EXPLAIN (ANALYZE, BUFFERS, TIMING FALSE, FORMAT JSON) '.$batches[0]->toSql(), $batches[0]->getBindings());
            $plan = json_decode($row->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR)[0]['Plan'];
            $sourceCtes = [];
            $discover = static function (array $node) use (&$discover, &$sourceCtes): bool {
                $containsSources = ($node['Relation Name'] ?? null) === 'ai_rag_status_sources';
                foreach ($node['Plans'] ?? [] as $child) { $containsSources = $discover($child) || $containsSources; }
                if ($containsSources && str_starts_with($node['Subplan Name'] ?? '', 'CTE ')) {
                    $sourceCtes[substr($node['Subplan Name'], 4)] = true;
                }

                return $containsSources;
            };
            $discover($plan);
            $rowsProcessed = 0;
            $collect = static function (array $node) use (&$collect, &$rowsProcessed, $sourceCtes): void {
                if (($node['Relation Name'] ?? null) === 'ai_rag_status_sources'
                    || (($node['Node Type'] ?? null) === 'CTE Scan' && isset($sourceCtes[$node['CTE Name'] ?? '']))) {
                    $rowsProcessed += (($node['Actual Rows'] ?? 0) + ($node['Rows Removed by Filter'] ?? 0)) * ($node['Actual Loops'] ?? 1);
                }
                foreach ($node['Plans'] ?? [] as $child) { $collect($child); }
            };
            $collect($plan);
            self::assertLessThanOrEqual(42000, $rowsProcessed, 'Mixed source counting must bound base and CTE work, including filtered rows and loops; temp blocks '.($plan['Temp Read Blocks'] ?? 0).'.');
            $package->updateQuietly(['organization_id' => $fixture->foreignOrganization->id]);
            self::assertNull($batches[0]->first());
            $package->updateQuietly(['organization_id' => $organizationId]);
            $fixture->owner->organizations()->updateExistingPivot($organizationId, ['is_active' => false]);
            self::assertNull($batches[0]->first());
        }, fresh: true);
    }
}
