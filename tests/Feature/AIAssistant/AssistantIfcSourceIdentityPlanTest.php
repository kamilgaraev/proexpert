<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

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
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantIfcSourceIdentityPlanTest extends TestCase
{
    public function test_sparse_ifc_sources_use_native_keys_and_keep_current_parent_access(): void
    {
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
            $source = RagSource::withoutEvents(fn () => RagSource::query()->create([
                'organization_id' => $organizationId, 'project_id' => $project->id, 'source_type' => 'design_additional',
                'entity_type' => 'design_ifc_model_element', 'entity_id' => (string) $key, 'title' => 'IFC',
                'checksum' => hash('sha256', 'ifc-'.$key),
            ]));
            $allowed[] = $source->id;
            if ($key === $firstId) {
                $firstSource = $source;
                $part = $source->replicate()->forceFill(['identity_part_key' => 'second-part']);
                $part->saveQuietly();
                $allowed[] = $part->id;
                $firstParts = [$source->id, $part->id];
            }
        }
        self::assertInstanceOf(RagSource::class, $firstSource);
        foreach (['0'.$firstId, '+'.$firstId, ' '.$firstId, $firstId.' ', $firstId.'.0', 'invalid', '9223372036854775808', '-9223372036854775809', '٠'] as $invalid) {
            $firstSource->replicate()->forceFill(['entity_id' => $invalid])->saveQuietly();
        }
        $foreignOrganization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $firstSource->replicate()->forceFill(['organization_id' => $foreignOrganization->id])->saveQuietly();
        foreach (['design_ifc_model_elements', 'ai_rag_sources', 'ai_rag_status_sources'] as $table) {
            DB::statement('ANALYZE '.$table);
        }
        $policy = app(AssistantDataAccessPolicy::class);
        $build = function () use ($policy, $fixture, $organizationId): array {
            $policy->prefetchEntitySchemaMetadata();

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
                if (($node['Relation Name'] ?? null) === 'design_ifc_model_elements') { $nativeScans[] = $node; }
                foreach ($node['Plans'] ?? [] as $child) { $collect($child); }
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
        DB::statement("INSERT INTO ai_rag_sources (organization_id, project_id, source_type, entity_type, entity_id, title, checksum) SELECT e.organization_id, e.project_id, 'design_additional', 'design_ifc_model_element', e.id::text, 'IFC', md5(e.id::text) FROM design_ifc_model_elements e WHERE e.organization_id = ? AND e.project_id = ? AND NOT EXISTS (SELECT 1 FROM ai_rag_sources s WHERE s.organization_id = e.organization_id AND s.project_id = e.project_id AND s.source_type = 'design_additional' AND s.entity_type = 'design_ifc_model_element' AND s.entity_id = e.id::text)", [$organizationId, $project->id]);
        DB::statement('ANALYZE ai_rag_status_sources');
        self::assertCount(20005, $read());
        $policy->withCurrentChecks($fixture->owner, $organizationId, function () use ($build): void {
            foreach ($build() as $batch) {
                self::assertStringNotContainsString('ARRAY_AGG', $batch->toSql());
            }
        }, fresh: true);
    }
}
