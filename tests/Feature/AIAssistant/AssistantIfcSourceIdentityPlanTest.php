<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\RagExpectedSource;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceIdentityQueryBuilder;
use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantFinanceTenderSourceSchema;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagExpectedSourceProjection;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\DesignAdditionalRagSource;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Models\DesignIfcModelElement;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use App\BusinessModules\Features\DesignManagement\Models\DesignReviewRound;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\Support\RagTestEmbedding;
use Tests\TestCase;

final class AssistantIfcSourceIdentityPlanTest extends TestCase
{
    public static function identityProjectionCases(): array
    {
        return ['stored' => [false], 'expected' => [true]];
    }

    public function test_expected_identity_scope_is_reused_only_for_the_exact_prefiltered_pair(): void
    {
        $builder = new AssistantSourceIdentityQueryBuilder;
        foreach ([null, ['other_source', 'design_ifc_model_element'], ['design_additional', 'other_entity']] as $scope) {
            $branch = $builder->identityBranch(DesignIfcModelElement::query(), 'design_additional', 'design_ifc_model_element',
                DB::table('ai_rag_expected_sources'), 'ai_rag_expected_sources', expectedProjection: true, prefilteredExpectedIdentity: $scope);
            self::assertStringContainsString('"ai_rag_expected_sources"."source_type" = ?', $branch->toSql());
            self::assertStringContainsString('"ai_rag_expected_sources"."entity_type" = ?', $branch->toSql());
            self::assertSame(['design_additional', 'design_ifc_model_element'], $branch->getBindings());
        }
        $prefiltered = DB::table('ai_rag_expected_sources')->where('source_type', 'design_additional')->where('entity_type', 'design_ifc_model_element');
        $branch = $builder->identityBranch(DesignIfcModelElement::query(), 'design_additional', 'design_ifc_model_element',
            $prefiltered, 'ai_rag_expected_sources', expectedProjection: true, prefilteredExpectedIdentity: ['design_additional', 'design_ifc_model_element']);
        self::assertSame(['design_additional', 'design_ifc_model_element'], $branch->getBindings());
        self::assertStringNotContainsString('"ai_rag_expected_sources"."source_type" = ?', $branch->toSql());
        self::assertStringNotContainsString('"ai_rag_expected_sources"."entity_type" = ?', $branch->toSql());
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
        $extraColumns = $expectedProjection ? 'generation, identity_project_id, pending_since' : 'title';
        $extraValues = $expectedProjection ? 'CAST(? AS uuid), e.project_id, CURRENT_TIMESTAMP' : "'IFC'";
        $bindings = $expectedProjection ? [$generation, $organizationId, $project->id] : [$organizationId, $project->id];
        DB::statement('INSERT INTO '.$table.' (organization_id, project_id, source_type, entity_type, entity_id, checksum, '.$extraColumns.") SELECT e.organization_id, e.project_id, 'design_additional', 'design_ifc_model_element', e.id::text, md5(e.id::text), ".$extraValues.' FROM design_ifc_model_elements e WHERE e.organization_id = ? AND e.project_id = ? AND NOT EXISTS (SELECT 1 FROM '.$table." s WHERE s.organization_id = e.organization_id AND s.project_id = e.project_id AND s.source_type = 'design_additional' AND s.entity_type = 'design_ifc_model_element' AND s.entity_id = e.id::text)", $bindings);
        DB::statement('ANALYZE '.($expectedProjection ? $table : 'ai_rag_status_sources'));
        self::assertCount(20005, $read());
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
        } else {
            $this->assertDenseExpectedCounts($policy, $fixture, $project, $generation, $firstId, $version, $derivative, $otherProject);
        }
    }

    private function assertDenseExpectedCounts(AssistantDataAccessPolicy $policy, AssistantRealAuthorizationFixture $fixture, Project $project, string $generation, int $firstId, DesignArtifactVersion $version, DesignModelDerivative $derivative, Project $otherProject): void
    {
        $organizationId = (int) $fixture->organization->id;
        DB::statement("INSERT INTO ai_rag_sources (organization_id, project_id, identity_project_id, identity_part_key, source_type, entity_type, entity_id, title, checksum) SELECT organization_id, project_id, identity_project_id, identity_part_key, source_type, entity_type, entity_id, 'IFC', checksum FROM ai_rag_expected_sources WHERE organization_id = ? AND generation = CAST(? AS uuid)", [$organizationId, $generation]);
        $source = RagSource::query()->where('organization_id', $organizationId)->where('entity_id', (string) $firstId)
            ->where('identity_part_key', '')->firstOrFail();
        DB::table('ai_rag_chunks')->insert([
            'organization_id' => $organizationId, 'project_id' => $project->id, 'source_id' => $source->id,
            'chunk_index' => 0, 'content' => 'Indexed IFC', 'content_hash' => hash('sha256', 'Indexed IFC'),
            'embedding' => json_encode(RagTestEmbedding::fromLeadingValues([1.0]), JSON_THROW_ON_ERROR),
        ]);
        $pendingSince = now()->subHours(2)->startOfSecond()->toDateTimeString();
        RagExpectedSource::query()->where('organization_id', $organizationId)->where('generation', $generation)
            ->where('identity_part_key', 'second-part')->update(['pending_since' => $pendingSince]);
        foreach (['ai_rag_sources', 'ai_rag_status_sources', 'ai_rag_expected_sources'] as $table) {
            DB::statement('ANALYZE '.$table);
        }
        DB::statement("SET LOCAL work_mem = '16MB'");
        DB::statement('SET LOCAL jit = off');
        DB::statement("SET LOCAL statement_timeout = '2s'");
        $projection = new RagExpectedSourceProjection(Mockery::mock(RagIndexer::class));
        $read = function (bool $collectProof) use ($projection, $policy, $fixture, $organizationId, $generation): array {
            return $policy->withCurrentChecks($fixture->owner, $organizationId, function () use ($projection, $policy, $fixture, $organizationId, $generation, $collectProof): array {
                return $projection->actorCounts($organizationId, $fixture->owner, $policy, ['design_additional'], $generation, collectProof: $collectProof);
            }, fresh: true);
        };
        DB::enableQueryLog();
        try {
            $started = hrtime(true);
            $counts = $read(false);
            $pipelineMs = (hrtime(true) - $started) / 1000000;
            $queries = array_values(array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'matched_source_cache AS MATERIALIZED')));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        self::assertSame(20005, (int) $counts['design_additional']->expected_count);
        self::assertSame(1, (int) $counts['design_additional']->indexed_count);
        self::assertSame(\Illuminate\Support\Carbon::parse($pendingSince)->getTimestamp(), \Illuminate\Support\Carbon::parse($counts['design_additional']->pending_since)->getTimestamp());
        $full = $read(true);
        foreach (['expected_count', 'indexed_count', 'pending_since'] as $field) {
            self::assertEquals($full['design_additional']->{$field}, $counts['design_additional']->{$field}, $field);
        }
        self::assertCount(1, $queries);
        $row = DB::selectOne('EXPLAIN (ANALYZE, BUFFERS, TIMING FALSE, FORMAT JSON) '.$queries[0]['query'], $queries[0]['bindings']);
        $plan = json_decode($row->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR)[0];
        $materialized = [];
        $matchedScanWork = 0;
        $collect = static function (array $node) use (&$collect, &$materialized, &$matchedScanWork): void {
            if (($node['Subplan Name'] ?? null) === 'CTE matched_source_cache') {
                $materialized[] = ($node['Actual Rows'] ?? 0) * ($node['Actual Loops'] ?? 1);
            }
            if (($node['Node Type'] ?? null) === 'CTE Scan' && ($node['CTE Name'] ?? null) === 'matched_source_cache') {
                $matchedScanWork += (($node['Actual Rows'] ?? 0) + ($node['Rows Removed by Filter'] ?? 0)) * ($node['Actual Loops'] ?? 1);
            }
            foreach ($node['Plans'] ?? [] as $child) {
                $collect($child);
            }
        };
        $collect($plan['Plan']);
        self::assertSame([20014], $materialized, 'The complete matched-source materialization must be preserved.');
        self::assertGreaterThan(0, $matchedScanWork);
        self::assertLessThanOrEqual(40028, $matchedScanWork, 'Expected counters must not rescan the complete matched-source cache for each identity.');
        if (getenv('MOST_RAG_STORED_QUERY_PROFILE') === '1') {
            fwrite(STDERR, 'IFC_EXPECTED_COUNTS_PROFILE '.json_encode([
                'expected_count' => 20005, 'indexed_count' => 1, 'pipeline_ms' => round($pipelineMs, 3),
                'planning_ms' => $plan['Planning Time'], 'execution_ms' => $plan['Execution Time'],
                'materialized_rows' => $materialized, 'temp_read_blocks' => $plan['Plan']['Temp Read Blocks'] ?? 0,
                'work_mem' => '16MB', 'jit' => 'off', 'matched_cache_rows_processed' => $matchedScanWork,
            ], JSON_THROW_ON_ERROR).PHP_EOL);
        }
        DB::table('ai_rag_status_sources')->where('organization_id', $organizationId)->update(['chunk_count' => 1, 'indexed_chunk_count' => 1]);
        $fullyIndexed = $read(false)['design_additional'];
        self::assertSame(20005, (int) $fullyIndexed->expected_count);
        self::assertSame(20005, (int) $fullyIndexed->indexed_count);
        self::assertNull($fullyIndexed->pending_since);
        $derivative->updateQuietly(['project_id' => $otherProject->id]);
        self::assertSame(2, (int) $read(false)['design_additional']->expected_count);
        $derivative->updateQuietly(['project_id' => $project->id]);
        $version->updateQuietly(['project_id' => $otherProject->id]);
        self::assertSame([], $read(false));
        $version->updateQuietly(['project_id' => $project->id]);
        $project->updateQuietly(['organization_id' => $fixture->foreignOrganization->id]);
        self::assertSame([], $read(false));
        $project->updateQuietly(['organization_id' => $organizationId]);
        $fixture->owner->organizations()->updateExistingPivot($organizationId, ['is_active' => false]);
        self::assertSame([], $read(false));
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
        foreach (['design_ifc_model_element', 'design_package_section'] as $type) {
            $source = RagSource::query()->where('organization_id', $organizationId)->where('entity_type', $type)->orderBy('id')->firstOrFail();
            DB::table('ai_rag_chunks')->insert([
                'organization_id' => $organizationId, 'project_id' => $project->id, 'source_id' => $source->id,
                'chunk_index' => 0, 'content' => 'Counter '.$type, 'content_hash' => hash('sha256', 'Counter '.$type),
                'embedding' => json_encode(RagTestEmbedding::fromLeadingValues([1.0]), JSON_THROW_ON_ERROR),
            ]);
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
            self::assertSame(2, (int) $counts->chunk_count);
            self::assertSame(2, (int) $counts->indexed_count);
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
            $coverage = $this->assertFullMixedCorpusCoverage($policy, $fixture);
            $package->updateQuietly(['organization_id' => $fixture->foreignOrganization->id]);
            self::assertNull($batches[0]->first());
            self::assertSame(0, $coverage->coverageForActor($organizationId, $fixture->owner, countsOnly: true)['stored_source_count']);
            $package->updateQuietly(['organization_id' => $organizationId]);
            $fixture->owner->organizations()->updateExistingPivot($organizationId, ['is_active' => false]);
            self::assertNull($batches[0]->first());
            self::assertSame(0, $coverage->coverageForActor($organizationId, $fixture->owner, countsOnly: true)['stored_source_count']);
        }, fresh: true);
    }

    private function assertFullMixedCorpusCoverage(AssistantDataAccessPolicy $policy, AssistantRealAuthorizationFixture $fixture): RagCoverageService
    {
        $organizationId = (int) $fixture->organization->id;
        $partial = static fn ($visible) => DB::query()->fromSub($visible, 'accessible_sources')->groupBy('accessible_sources.source_type')
            ->selectRaw('accessible_sources.source_type, COUNT(*) AS stored_count, COALESCE(pg_catalog.SUM(accessible_sources.chunk_count), 0) AS chunk_count, COUNT(CASE WHEN accessible_sources.indexed_chunk_count > 0 THEN 1 END) AS indexed_count');
        $combine = static fn ($visible) => DB::query()->fromSub($visible, 'accessible_sources')->groupBy('accessible_sources.source_type')
            ->selectRaw('accessible_sources.source_type, pg_catalog.SUM(accessible_sources.stored_count) AS stored_count, pg_catalog.SUM(accessible_sources.chunk_count) AS chunk_count, pg_catalog.SUM(accessible_sources.indexed_count) AS indexed_count');
        $profileBatches = [];
        $profiles = [];
        foreach (['baseline' => false, 'specialized' => true] as $variant => $specialize) {
            $started = hrtime(true);
            $batches = $policy->aggregateSourceIdentityBatches(
                RagSource::query()->from('ai_rag_status_sources as ai_rag_sources'), $fixture->owner, $organizationId,
                ['ai_rag_sources.id', 'ai_rag_sources.source_type', 'ai_rag_sources.project_id', 'ai_rag_sources.chunk_count', 'ai_rag_sources.indexed_chunk_count'],
                $combine, aggregateBranch: $partial, specializeDesignCandidates: $specialize,
            );
            $compileMs = round((hrtime(true) - $started) / 1000000, 3);
            self::assertCount(1, $batches);
            $totals = [0, 0, 0];
            foreach ($batches as $batch) {
                foreach ($batch->get() as $row) {
                    self::assertSame('design_additional', $row->source_type);
                    foreach (['stored_count', 'chunk_count', 'indexed_count'] as $index => $field) { $totals[$index] += (int) $row->{$field}; }
                }
            }
            $pipelineMs = round((hrtime(true) - $started) / 1000000, 3);
            self::assertSame([20007, 2, 2], $totals);
            if (getenv('MOST_RAG_STORED_QUERY_PROFILE') === '1') {
                $profileBatches[$variant] = $batches;
                $profiles[$variant] = ['variant' => $variant, 'compile_ms' => $compileMs, 'pipeline_ms' => $pipelineMs, 'measurements' => []];
            }
        }
        if ($profiles !== []) {
            $previousWorkMem = DB::selectOne('SHOW work_mem')->work_mem;
            DB::statement("SET LOCAL work_mem = '16MB'");
            try {
                foreach (['baseline', 'specialized', 'specialized', 'baseline', 'specialized', 'baseline', 'baseline', 'specialized'] as $repeat => $variant) {
                    foreach ($profileBatches[$variant] as $ordinal => $batch) {
                        $started = hrtime(true);
                        $row = DB::selectOne('EXPLAIN (ANALYZE, BUFFERS, TIMING FALSE, FORMAT JSON) '.$batch->toSql(), $batch->getBindings());
                        $plan = json_decode($row->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR)[0];
                        $profiles[$variant]['measurements'][] = ['ordinal' => $ordinal + 1, 'repeat' => $repeat, 'sql_bytes' => strlen($batch->toSql()),
                            'bindings' => count($batch->getBindings()), 'wall_ms' => round((hrtime(true) - $started) / 1000000, 3),
                            'planning_ms' => $plan['Planning Time'], 'execution_ms' => $plan['Execution Time']];
                    }
                }
                foreach ($profiles as $profile) { fwrite(STDOUT, PHP_EOL.'STORED_FULL_COUNTER_PROFILE '.json_encode($profile, JSON_THROW_ON_ERROR).PHP_EOL); }
            } finally {
                DB::select('SELECT set_config(?, ?, true)', ['work_mem', $previousWorkMem]);
            }
        }
        Queue::fake();
        $registry = new RagSourceRegistry([new DesignAdditionalRagSource]);
        $coverage = new RagCoverageService($registry, Mockery::mock(RagIndexer::class), $policy);
        $full = $coverage->coverageForActor($organizationId, $fixture->owner);
        $counts = $coverage->coverageForActor($organizationId, $fixture->owner, countsOnly: true);
        foreach (['source_count' => 20007, 'stored_source_count' => 20007, 'chunk_count' => 2, 'indexed_source_count' => 2] as $field => $expected) {
            self::assertSame($expected, $full[$field], 'Full '.$field);
            self::assertSame($full[$field], $counts[$field], 'Counts-only '.$field);
        }
        $calls = 0;
        $failure = new \RuntimeException('stop_first_design_batch');
        try {
            $policy->aggregateSourceIdentityBatches(
                RagSource::query()->from('ai_rag_status_sources as ai_rag_sources'), $fixture->owner, $organizationId,
                ['ai_rag_sources.id', 'ai_rag_sources.source_type', 'ai_rag_sources.project_id', 'ai_rag_sources.chunk_count', 'ai_rag_sources.indexed_chunk_count'],
                $combine, aggregateBranch: $partial, consume: static function () use (&$calls, $failure): void { $calls++; throw $failure; }, specializeDesignCandidates: true,
            );
            self::fail('A failed consumer must stop design batch traversal.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
            self::assertSame(1, $calls);
        }
        $source = RagSource::query()->where('organization_id', $organizationId)->where('entity_type', 'design_ifc_model_element')->firstOrFail();
        foreach (array_merge(['', 'unknown_design_entity'], array_keys(AssistantFinanceTenderSourceSchema::revisions())) as $entityType) {
            $source->replicate()->forceFill(['entity_type' => $entityType, 'identity_part_key' => 'unknown-'.$entityType])->saveQuietly();
        }
        foreach ([false, true] as $countsOnly) {
            $status = $coverage->coverageForActor($organizationId, $fixture->owner, countsOnly: $countsOnly);
            self::assertSame(20007, $status['stored_source_count']);
            self::assertSame(2, $status['chunk_count']);
            self::assertSame(2, $status['indexed_source_count']);
        }

        return $coverage;
    }
}
