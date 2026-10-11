<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\RagExpectedSource;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AssistantRealAuthorizationFixture;
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
        }
    }
}
