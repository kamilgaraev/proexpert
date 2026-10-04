<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
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

    private function package(int $organizationId, int $projectId): DesignPackage
    {
        return DesignPackage::withoutEvents(fn () => DesignPackage::query()->create(['organization_id' => $organizationId, 'project_id' => $projectId, 'title' => 'Комплект', 'status' => 'draft']));
    }
}
