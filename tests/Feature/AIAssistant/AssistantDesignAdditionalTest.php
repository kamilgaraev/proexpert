<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\DesignAdditionalRagSource;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Models\DesignIfcModelElement;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSet;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSetRevision;
use App\BusinessModules\Features\DesignManagement\Models\DesignNormativeSource;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use App\BusinessModules\Features\DesignManagement\Models\DesignReviewRound;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantDesignAdditionalTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_twenty_collectors_query_actual_tables_and_never_select_derivative_geometry_or_secrets(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $package = $this->package($fixture->organization->id, $project->id);
        $artifact = DesignArtifact::withoutEvents(fn () => DesignArtifact::query()->create(['organization_id' => $fixture->organization->id,
            'project_id' => $project->id, 'package_id' => $package->id, 'title' => 'Модель', 'artifact_type' => 'model']));
        $version = DesignArtifactVersion::withoutEvents(fn () => DesignArtifactVersion::query()->create(['organization_id' => $fixture->organization->id,
            'project_id' => $project->id, 'artifact_id' => $artifact->id, 'title' => 'Версия', 'version_number' => '1',
            'source_file_path' => 'private-source', 'source_original_name' => 'model.ifc', 'source_mime_type' => 'application/octet-stream', 'source_size_bytes' => 1]));
        $derivative = DesignModelDerivative::withoutEvents(fn () => DesignModelDerivative::query()->create(['organization_id' => $fixture->organization->id,
            'project_id' => $project->id, 'version_id' => $version->id, 'progress_percent' => 35, 'status' => 'processing',
            'derivative_file_path' => 'private-geometry-secret', 'failed_reason' => 'password-secret', 'metadata' => ['credentials' => 'token-secret']]));
        DB::enableQueryLog();
        $chunks = iterator_to_array((function () use ($fixture) { yield from (new DesignAdditionalRagSource)->collectForOrganization($fixture->organization->id); })());
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog(); DB::flushQueryLog();
        $selected = array_values(array_filter($queries, static fn (string $query): bool => str_contains($query, 'from "design_model_derivatives"')));
        self::assertNotEmpty($selected);
        foreach ($selected as $query) {
            self::assertStringNotContainsString('derivative_file_path', $query);
            self::assertStringNotContainsString('"metadata"', $query);
            self::assertStringNotContainsString('failed_reason', $query);
        }
        $chunk = array_values(array_filter($chunks, static fn ($chunk): bool => $chunk->entityType === 'design_model_derivative' && (int) $chunk->entityId === $derivative->id))[0];
        self::assertSame(35, $chunk->metadata['progress_percent']);
        self::assertSame('processing', $chunk->metadata['status']);
        self::assertStringNotContainsString('secret', $chunk->content);
        self::assertTrue(app(AssistantDataAccessPolicy::class)->canReadSource($fixture->owner, $fixture->organization->id,
            ['organization_id' => $fixture->organization->id, 'source_type' => 'design_additional', 'entity_type' => 'design_model_derivative', 'entity_id' => $derivative->id, 'project_id' => $project->id]));
    }

    public function test_section_private_project_and_foreign_parent_are_filtered_before_limit_using_real_roles(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $fixture->memberRole->update(['module_permissions' => ['ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view'],
            'design-management' => ['design-management.view', 'design-management.documents.view']]]);
        $visible = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $hidden = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $fixture->member->assignedProjects()->attach($visible->id, ['is_active' => true, 'role' => 'member']);
        foreach ([$hidden, $hidden, $hidden, $hidden, $visible] as $project) {
            $package = $this->package($fixture->organization->id, $project->id);
            $section = DesignPackageSection::query()->create(['organization_id' => $fixture->organization->id, 'project_id' => $project->id,
                'package_id' => $package->id, 'code' => 'АР', 'title' => 'Архитектура', 'project_stage' => $package->project_stage]);
        }
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertSame($section->id, $policy->entityQuery($fixture->member, $fixture->organization->id, 'design_package_section')->orderBy('id')->limit(1)->firstOrFail()->id);
        self::assertFalse($policy->canReadEntity($fixture->foreignOwner, $fixture->organization->id, 'design_package_section', $section->id));
        $package->updateQuietly(['organization_id' => $fixture->foreignOrganization->id]);
        self::assertFalse($policy->canReadEntity($fixture->member, $fixture->organization->id, 'design_package_section', $section->id));
    }

    public function test_child_without_organization_inherits_current_project_and_models_permission(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $fixture->memberRole->update(['module_permissions' => ['ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view'],
            'design-management' => ['design-management.view', 'design-management.models.view']]]);
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $set = DesignModelSet::query()->create(['organization_id' => $fixture->organization->id, 'project_id' => $project->id, 'title' => 'Набор', 'revision' => 1]);
        $revision = DesignModelSetRevision::query()->create(['model_set_id' => $set->id, 'revision' => 1, 'version_ids' => [], 'transforms' => ['private' => 'geometry-secret']]);
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertFalse($policy->canReadEntity($fixture->member, $fixture->organization->id, 'design_model_set_revision', $revision->id));
        $fixture->member->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        self::assertTrue($policy->canReadEntity($fixture->member, $fixture->organization->id, 'design_model_set_revision', $revision->id));
        $chunk = iterator_to_array((function () use ($fixture, $revision) { yield from (new DesignAdditionalRagSource)->collectEntity($fixture->organization->id, 'design_model_set_revision', $revision->id); })())[0];
        self::assertSame($project->id, $chunk->projectId);
        self::assertStringNotContainsString('geometry-secret', $chunk->content);
        $fixture->memberRole->update(['module_permissions' => ['ai-assistant' => ['ai_assistant.chat'], 'design-management' => ['design-management.view']]]);
        self::assertFalse($policy->canReadEntity($fixture->member, $fixture->organization->id, 'design_model_set_revision', $revision->id));
    }

    public function test_global_normative_catalog_requires_current_module_permission_and_active_source(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $fixture->memberRole->update(['module_permissions' => ['ai-assistant' => ['ai_assistant.chat'],
            'design-management' => ['design-management.normative_catalog.view']]]);
        $normative = DesignNormativeSource::query()->create(['code' => 'assistant-proof', 'title' => 'Норматив', 'version' => '1', 'status' => 'active', 'source_url' => 'https://secret.example/token']);
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertTrue($policy->canReadEntity($fixture->member, $fixture->organization->id, 'design_normative_source', $normative->id));
        $normative->updateQuietly(['status' => 'archived']);
        self::assertFalse($policy->canReadEntity($fixture->member, $fixture->organization->id, 'design_normative_source', $normative->id));
        $normative->updateQuietly(['status' => 'active']);
        $fixture->memberRole->update(['module_permissions' => ['ai-assistant' => ['ai_assistant.chat']]]);
        self::assertFalse($policy->canReadEntity($fixture->member, $fixture->organization->id, 'design_normative_source', $normative->id));
        $fixture->memberRole->update(['module_permissions' => ['ai-assistant' => ['ai_assistant.chat'], 'design-management' => ['design-management.normative_catalog.view']]]);
        $fixture->subscription->update(['status' => 'expired', 'current_period_end_at' => now()->subSecond()]);
        self::assertFalse($policy->canReadEntity($fixture->member, $fixture->organization->id, 'design_normative_source', $normative->id));
    }

    public function test_ifc_parent_project_and_derivative_version_must_match_current_rows(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $otherProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $package = $this->package($fixture->organization->id, $project->id);
        $artifact = DesignArtifact::withoutEvents(fn () => DesignArtifact::query()->create([
            'organization_id' => $fixture->organization->id, 'project_id' => $project->id,
            'package_id' => $package->id, 'title' => 'Модель', 'artifact_type' => 'model',
        ]));
        $version = DesignArtifactVersion::withoutEvents(fn () => DesignArtifactVersion::query()->create([
            'organization_id' => $fixture->organization->id, 'project_id' => $project->id, 'artifact_id' => $artifact->id,
            'title' => 'Версия', 'version_number' => '1', 'source_file_path' => 'private.ifc',
            'source_original_name' => 'model.ifc', 'source_mime_type' => 'application/octet-stream', 'source_size_bytes' => 1,
        ]));
        $otherVersion = $version->replicate()->forceFill(['version_number' => '2']);
        $otherVersion->save();
        $derivative = DesignModelDerivative::withoutEvents(fn () => DesignModelDerivative::query()->create([
            'organization_id' => $fixture->organization->id, 'project_id' => $project->id, 'version_id' => $version->id,
        ]));
        $element = DesignIfcModelElement::withoutEvents(fn () => DesignIfcModelElement::query()->create([
            'organization_id' => $fixture->organization->id, 'project_id' => $project->id,
            'version_id' => $version->id, 'derivative_id' => $derivative->id, 'express_id' => 123,
        ]));
        $policy = app(AssistantDataAccessPolicy::class);
        $visible = fn () => $policy->canReadEntity($fixture->owner, $fixture->organization->id, 'design_ifc_model_element', $element->id);
        self::assertTrue($visible());
        $derivative->updateQuietly(['version_id' => $otherVersion->id]);
        self::assertFalse($visible());
        $derivative->updateQuietly(['version_id' => $version->id, 'project_id' => $otherProject->id]);
        self::assertFalse($visible());
        $element->updateQuietly(['derivative_id' => null]);
        self::assertTrue($visible());
        $version->updateQuietly(['project_id' => $otherProject->id]);
        self::assertFalse($visible());
    }

    public function test_template_without_normative_source_is_readable_but_archived_parent_and_current_permission_remain_closed(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $fixture->memberRole->update(['module_permissions' => ['ai-assistant' => ['ai_assistant.chat'],
            'design-management' => ['design-management.normative_catalog.view']]]);
        $template = \App\BusinessModules\Features\DesignManagement\Models\DesignDocumentTemplate::query()->create([
            'normative_source_id' => null, 'profile_code' => 'assistant-null-parent', 'project_stage' => 'rd',
            'section_code' => 'AR', 'section_title' => 'Архитектура', 'document_code' => 'QA',
            'document_title' => 'Шаблон без нормативного источника', 'artifact_type' => 'text_document']);
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertTrue($policy->canReadEntity($fixture->member, $fixture->organization->id, 'design_document_template', $template->id));
        self::assertCount(1, [...(new DesignAdditionalRagSource)->collectEntity($fixture->organization->id, 'design_document_template', $template->id)]);
        $source = DesignNormativeSource::query()->create(['code' => 'assistant-template-parent', 'title' => 'Норматив', 'version' => '1', 'status' => 'archived']);
        $template->updateQuietly(['normative_source_id' => $source->id]);
        self::assertFalse($policy->canReadEntity($fixture->member, $fixture->organization->id, 'design_document_template', $template->id));
        self::assertSame([], [...(new DesignAdditionalRagSource)->collectEntity($fixture->organization->id, 'design_document_template', $template->id)]);
        $source->updateQuietly(['status' => 'active']);
        self::assertTrue($policy->canReadEntity($fixture->member, $fixture->organization->id, 'design_document_template', $template->id));
        $template->updateQuietly(['normative_source_id' => null]);
        $fixture->memberRole->update(['module_permissions' => ['ai-assistant' => ['ai_assistant.chat']]]);
        self::assertFalse($policy->canReadEntity($fixture->member, $fixture->organization->id, 'design_document_template', $template->id));
    }

    public function test_nullable_ifc_derivative_uses_identity_lookup_and_preserves_scope_across_pages(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $otherProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $package = $this->package($fixture->organization->id, $project->id);
        $artifact = DesignArtifact::withoutEvents(fn () => DesignArtifact::query()->create([
            'organization_id' => $fixture->organization->id, 'project_id' => $project->id,
            'package_id' => $package->id, 'title' => 'Модель', 'artifact_type' => 'model',
        ]));
        $version = DesignArtifactVersion::withoutEvents(fn () => DesignArtifactVersion::query()->create([
            'organization_id' => $fixture->organization->id, 'project_id' => $project->id,
            'artifact_id' => $artifact->id, 'title' => 'Версия', 'version_number' => '1',
            'source_file_path' => 'private.ifc', 'source_original_name' => 'model.ifc',
            'source_mime_type' => 'application/octet-stream', 'source_size_bytes' => 1,
        ]));
        $otherVersion = $version->replicate()->forceFill(['version_number' => '2']);
        DesignArtifactVersion::withoutEvents(fn () => $otherVersion->save());
        $derivative = DesignModelDerivative::withoutEvents(fn () => DesignModelDerivative::query()->create([
            'organization_id' => $fixture->organization->id, 'project_id' => $project->id,
            'version_id' => $version->id,
        ]));
        foreach ([
            ['version_id' => $otherVersion->id],
            ['project_id' => $otherProject->id],
            ['organization_id' => $fixture->foreignOrganization->id],
        ] as $offset => $attributes) {
            $invalidParent = $derivative->replicate()->forceFill($attributes + ['derivative_format' => 'scope-proof-'.$offset]);
            DesignModelDerivative::withoutEvents(fn () => $invalidParent->save());
            DesignIfcModelElement::withoutEvents(fn () => DesignIfcModelElement::query()->create([
                'organization_id' => $fixture->organization->id, 'project_id' => $project->id,
                'version_id' => $version->id, 'derivative_id' => $invalidParent->id,
                'express_id' => $offset + 1,
            ]));
        }
        $expected = [];
        $withoutDerivative = [];
        for ($offset = 0; $offset < 55; $offset++) {
            $element = DesignIfcModelElement::withoutEvents(fn () => DesignIfcModelElement::query()->create([
                'organization_id' => $fixture->organization->id, 'project_id' => $project->id,
                'version_id' => $version->id, 'derivative_id' => $offset % 2 === 0 ? null : $derivative->id,
                'express_id' => $offset + 10,
            ]));
            $expected[] = $element->id;
            if ($element->derivative_id === null) {
                $withoutDerivative[] = $element->id;
            }
        }
        $collect = static function () use ($fixture): array {
            $chunks = [...(new DesignAdditionalRagSource)->collectForOrganization($fixture->organization->id)];
            return array_values(array_map(
                static fn ($chunk): int => (int) $chunk->entityId,
                array_filter($chunks, static fn ($chunk): bool => $chunk->entityType === 'design_ifc_model_element'),
            ));
        };
        DB::enableQueryLog();
        try {
            self::assertSame($expected, $collect());
            $queries = array_values(array_filter(array_column(DB::getQueryLog(), 'query'),
                static fn (string $query): bool => str_starts_with($query, 'select "design_ifc_model_elements".')));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        self::assertCount(2, $queries);
        foreach ($queries as $query) {
            self::assertStringContainsString('"design_model_derivatives"."id" = "design_ifc_model_elements"."derivative_id"', $query);
        }
        $derivative->updateQuietly(['organization_id' => $fixture->foreignOrganization->id]);
        self::assertSame($withoutDerivative, $collect());
        $version->updateQuietly(['project_id' => $otherProject->id]);
        self::assertSame([], $collect());
    }

    public function test_single_source_counter_batch_shares_candidates_across_identities_and_keeps_current_access(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $permissions = ['design-management.view', 'design-management.documents.view', 'design-management.review'];
        $fixture->memberRole->update(['module_permissions' => ['ai-assistant' => ['ai_assistant.chat'],
            'project-management' => ['projects.view'], 'design-management' => $permissions]]);
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $hidden = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $fixture->member->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        $package = $this->package($fixture->organization->id, $project->id);
        $section = DesignPackageSection::withoutEvents(fn () => DesignPackageSection::query()->create([
            'organization_id' => $fixture->organization->id, 'project_id' => $project->id,
            'package_id' => $package->id, 'code' => 'AR', 'title' => 'Section', 'project_stage' => $package->project_stage,
        ]));
        $round = DesignReviewRound::withoutEvents(fn () => DesignReviewRound::query()->create([
            'organization_id' => $fixture->organization->id, 'project_id' => $project->id,
            'package_id' => $package->id, 'round_number' => 1,
        ]));
        foreach (['design_package_section' => $section->id, 'design_review_round' => $round->id] as $type => $id) {
            DB::statement("INSERT INTO ai_rag_sources (organization_id, project_id, source_type, entity_type, entity_id, identity_project_id, identity_part_key, title, checksum) SELECT ?, ?, 'design_additional', ?, ?, ?, 'counter-part-' || n, 'Counter source', md5(n::text) FROM generate_series(1, 5000) n",
                [$fixture->organization->id, $project->id, $type, (string) $id, $project->id]);
        }
        foreach ([[$fixture->organization->id, $hidden->id, (string) $section->id],
            [$fixture->foreignOrganization->id, $project->id, (string) $section->id],
            [$fixture->organization->id, $project->id, '0'.$section->id]] as [$organizationId, $projectId, $entityId]) {
            RagSource::withoutEvents(fn () => RagSource::query()->create(['organization_id' => $organizationId,
                'project_id' => $projectId, 'source_type' => 'design_additional', 'entity_type' => 'design_package_section',
                'entity_id' => $entityId, 'identity_part_key' => 'excluded', 'title' => 'Excluded', 'checksum' => hash('sha256', $entityId)]));
        }
        DB::statement('ANALYZE ai_rag_status_sources');
        DB::statement("SET LOCAL work_mem = '64kB'");
        $policy = app(AssistantDataAccessPolicy::class);
        $compile = static fn () => $policy->withCurrentChecks($fixture->member, $fixture->organization->id,
            static fn () => $policy->aggregateSourceIdentityBatches(
                RagSource::query()->from('ai_rag_status_sources as ai_rag_sources'), $fixture->member, $fixture->organization->id,
                ['ai_rag_sources.id', 'ai_rag_sources.source_type', 'ai_rag_sources.project_id', 'ai_rag_sources.chunk_count', 'ai_rag_sources.indexed_chunk_count'],
                static fn ($visible) => DB::query()->fromSub($visible, 'accessible_sources')->groupBy('accessible_sources.source_type')
                    ->selectRaw('accessible_sources.source_type, COUNT(*) AS stored_count, COALESCE(pg_catalog.SUM(accessible_sources.chunk_count), 0) AS chunk_count, COUNT(CASE WHEN accessible_sources.indexed_chunk_count > 0 THEN 1 END) AS indexed_count')),
            fresh: true);
        $batches = $compile();
        self::assertCount(1, $batches);
        self::assertSame(10000, (int) $batches[0]->first()->stored_count);
        $result = DB::selectOne('EXPLAIN (ANALYZE, BUFFERS, TIMING FALSE, FORMAT JSON) '.$batches[0]->toSql(), $batches[0]->getBindings());
        $sourceScans = 0;
        $sourceRows = 0;
        $walk = static function (array $node) use (&$walk, &$sourceScans, &$sourceRows): void {
            if (($node['Relation Name'] ?? null) === 'ai_rag_status_sources') {
                $sourceScans++;
                $sourceRows += (($node['Actual Rows'] ?? 0) + ($node['Rows Removed by Filter'] ?? 0)) * ($node['Actual Loops'] ?? 1);
            }
            foreach ($node['Plans'] ?? [] as $child) { $walk($child); }
        };
        $walk(json_decode($result->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR)[0]['Plan']);
        self::assertLessThanOrEqual(2, $sourceScans, 'A single source batch must share its candidate projection across entity branches.');
        self::assertLessThanOrEqual(20006, $sourceRows);
        $package->updateQuietly(['organization_id' => $fixture->foreignOrganization->id]);
        self::assertNull($batches[0]->first());
        $package->updateQuietly(['organization_id' => $fixture->organization->id]);
        $fixture->member->assignedProjects()->updateExistingPivot($project->id, ['is_active' => false]);
        self::assertNull($batches[0]->first());
        $fixture->member->assignedProjects()->updateExistingPivot($project->id, ['is_active' => true]);
        $fixture->memberRole->update(['module_permissions' => ['ai-assistant' => ['ai_assistant.chat'],
            'project-management' => ['projects.view'], 'design-management' => ['design-management.view', 'design-management.documents.view']]]);
        self::assertSame(5000, (int) $compile()[0]->first()->stored_count);
        DB::table('organization_user')->where('organization_id', $fixture->organization->id)->where('user_id', $fixture->member->id)->update(['is_active' => false]);
        self::assertNull($batches[0]->first());
    }

    private function package(int $organizationId, int $projectId): DesignPackage
    {
        return DesignPackage::withoutEvents(fn () => DesignPackage::query()->create(['organization_id' => $organizationId, 'project_id' => $projectId, 'title' => 'Комплект', 'status' => 'draft']));
    }
}
