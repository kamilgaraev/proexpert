<?php

declare(strict_types=1);

namespace Tests\Feature\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use App\BusinessModules\Features\DesignManagement\Models\DesignWorkflowEvent;
use App\BusinessModules\Features\DesignManagement\Services\DesignCompositionService;
use App\BusinessModules\Features\DesignManagement\Services\DesignDocumentArtifactService;
use App\BusinessModules\Features\DesignManagement\Services\DesignPackageIssueRegisterService;
use App\BusinessModules\Features\DesignManagement\Services\DesignWorkflowService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use DomainException;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class DesignAuditWorkflowTest extends TestCase
{
    public function test_release_register_remains_immutable_after_return_and_changed_document(): void
    {
        [$actor, $package, $section, $artifact, $version] = $this->fixture();
        $workflow = app(DesignWorkflowService::class);
        foreach (['submit_norm_control', 'submit_customer_review', 'approve', 'issue'] as $action) {
            $package = $workflow->transition($package, (int) $actor->id, $action);
        }
        $event = DesignWorkflowEvent::query()->where('package_id', $package->id)->where('action', 'issue')->sole();
        $register = app(DesignPackageIssueRegisterService::class)->build($package, $event->id, $actor);
        $this->assertSame('issued', $section->fresh()->status->value);
        $workflow->transition($package, (int) $actor->id, 'return_to_work', 'Новая ревизия');
        $artifact->update(['document_title' => 'Изменённый документ']);
        $package->update(['title' => 'Изменённый комплект']);
        $after = app(DesignPackageIssueRegisterService::class)->build($package->fresh(), $event->id, $actor);
        $this->assertSame($register, $after);
        $this->assertTrue($after['is_snapshot']);
        $this->assertSame($version->id, $after['sections'][0]['documents'][0]['current_version_id']);
    }

    public function test_incomplete_sheet_replacement_keeps_existing_rows_and_issued_sheets_are_locked(): void
    {
        [$actor, $package, $section, $artifact, $version] = $this->fixture();
        $version->sheets()->create(['organization_id' => $package->organization_id, 'project_id' => $package->project_id, 'package_id' => $package->id, 'section_id' => $section->id, 'artifact_id' => $artifact->id, 'sheet_number' => '1', 'sheet_title' => 'Исходный лист', 'file_page_number' => 1, 'status' => 'active']);
        try {
            app(DesignDocumentArtifactService::class)->replaceSheets($version, (int) $actor->id, [['sheet_number' => '1', 'sheet_title' => ' ']]);
            $this->fail('Incomplete sheet replacement must fail before deletion.');
        } catch (ValidationException) {
            $this->assertSame('Исходный лист', $version->sheets()->sole()->sheet_title);
        }
        DesignWorkflowEvent::query()->create(['organization_id' => $package->organization_id, 'project_id' => $package->project_id, 'package_id' => $package->id, 'actor_id' => $actor->id, 'action' => 'issue', 'to_status' => 'issued', 'metadata' => ['artifact_version_ids' => [$version->id]]]);
        try {
            app(DesignDocumentArtifactService::class)->replaceSheets($version, (int) $actor->id, [['sheet_number' => '1', 'sheet_title' => 'Новый лист']]);
            $this->fail('Previously issued sheets must stay immutable.');
        } catch (DomainException) {
            $this->assertSame('Исходный лист', $version->sheets()->sole()->sheet_title);
        }
    }

    public function test_removing_section_from_composition_preserves_document_history(): void
    {
        [$actor, $package, $section, $artifact, $version] = $this->fixture();
        app(DesignCompositionService::class)->createRevision($package, $actor, ['composition' => ['sections' => [['code' => 'NEW', 'title' => 'Новый раздел', 'documents' => []]]]]);
        $this->assertFalse($section->fresh()->metadata['composition_active']);
        $this->assertSame($artifact->id, $version->fresh()->artifact_id);
        $this->assertDatabaseHas('design_artifact_versions', ['id' => $version->id]);
        $this->assertDatabaseHas('design_package_sections', ['id' => $section->id]);
    }

    public function test_removed_last_document_does_not_reappear_in_approved_scope_or_release(): void
    {
        [$actor, $package, $section, $artifact, $version] = $this->fixture();
        $revision = app(DesignCompositionService::class)->createRevision($package, $actor, ['composition' => ['sections' => [
            ['code' => 'AR', 'title' => 'АР', 'required' => false, 'documents' => []],
            ['code' => 'ACTIVE', 'required' => false, 'documents' => [['document_code' => 'ACTIVE-01', 'required' => false]]],
        ]]]);
        $this->activeVersion($package, $version);
        app(DesignCompositionService::class)->approve($revision, $actor);
        $package = $package->fresh(['sections.artifacts.currentVersion', 'artifacts.currentVersion']);
        $scope = new \App\BusinessModules\Features\DesignManagement\Support\DesignCompletenessScope($package);
        $this->assertFalse($scope->artifacts()->contains('id', $artifact->id));
        $this->assertFalse($artifact->fresh()->metadata['composition_active']);
        foreach (['submit_norm_control', 'submit_customer_review', 'approve', 'issue'] as $action) {
            $package = app(DesignWorkflowService::class)->transition($package, (int) $actor->id, $action);
        }
        $register = app(DesignPackageIssueRegisterService::class)->build($package, null, $actor);
        $this->assertSame([], $register['sections'][0]['documents']);
        $this->assertTrue($register['is_snapshot']);
        $this->assertDatabaseHas('design_artifacts', ['id' => $artifact->id, 'section_id' => $section->id]);
    }

    public function test_excluded_document_is_not_pinned_or_frozen_by_release(): void
    {
        [$actor, $package, $section, $artifact, $version] = $this->fixture();
        $composition = app(DesignCompositionService::class);
        $revision = $composition->createRevision($package, $actor, ['composition' => ['sections' => [
            ['code' => 'AR', 'required' => true, 'documents' => [['document_code' => 'AR-01', 'required' => true]]],
            ['code' => 'ACTIVE', 'required' => false, 'documents' => [['document_code' => 'ACTIVE-01', 'required' => false]]],
        ]]]);
        $activeVersion = $this->activeVersion($package, $version);
        $composition->exclude($revision, $actor, ['item_key' => 'AR', 'reason' => 'Раздел исключён', 'expected_state_version' => 1]);
        $composition->approve($revision->fresh(), $actor, 2);
        foreach (['submit_norm_control', 'submit_customer_review', 'approve', 'issue'] as $action) {
            $package = app(DesignWorkflowService::class)->transition($package->fresh(), (int) $actor->id, $action);
        }
        $event = DesignWorkflowEvent::query()->where('package_id', $package->id)->where('action', 'issue')->sole();
        $this->assertSame([$activeVersion->id], $event->metadata['artifact_version_ids']);
        $package = app(DesignWorkflowService::class)->transition($package, (int) $actor->id, 'return_to_work', 'Историческое уточнение');
        $updated = app(DesignDocumentArtifactService::class)->replaceSheets($version->fresh(), (int) $actor->id, [['sheet_number' => '1', 'sheet_title' => 'Исторический лист']]);
        $this->assertSame('Исторический лист', $updated->sheets->sole()->sheet_title);
        $this->assertSame(['ACTIVE'], array_column(app(DesignPackageIssueRegisterService::class)->build($package, $event->id, $actor)['sections'], 'code'));
    }

    private function activeVersion(DesignPackage $package, DesignArtifactVersion $source): DesignArtifactVersion
    {
        $artifact = $package->artifacts()->where('document_code', 'ACTIVE-01')->sole();
        $version = $source->replicate();
        $version->fill(['artifact_id' => $artifact->id, 'title' => $artifact->title, 'source_file_path' => 'org-'.$package->organization_id.'/active.pdf'])->save();
        return $version;
    }

    private function fixture(): array
    {
        $context = AdminApiTestContext::create();
        $this->mock(AuthorizationService::class, static function (MockInterface $mock): void {
            $mock->shouldReceive('can')->andReturn(true);
        });
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $identity = ['organization_id' => $context->organization->id, 'project_id' => $project->id];
        $package = DesignPackage::query()->create($identity + ['created_by' => $context->user->id, 'updated_by' => $context->user->id, 'title' => 'Комплект', 'project_stage' => 'pd', 'status' => 'draft', 'metadata' => []]);
        $section = DesignPackageSection::query()->create($identity + ['package_id' => $package->id, 'code' => 'AR', 'title' => 'Архитектура', 'project_stage' => 'pd', 'required' => true, 'metadata' => ['documents' => [['document_code' => 'AR-01', 'required' => true, 'allowed_formats' => ['pdf']]]]]);
        $artifact = DesignArtifact::query()->create($identity + ['package_id' => $package->id, 'section_id' => $section->id, 'created_by' => $context->user->id, 'updated_by' => $context->user->id, 'artifact_type' => 'text_document', 'document_code' => 'AR-01', 'document_title' => 'Пояснительная записка', 'title' => 'Пояснительная записка', 'status' => 'active']);
        $version = DesignArtifactVersion::query()->create($identity + ['artifact_id' => $artifact->id, 'title' => $artifact->title, 'created_by' => $context->user->id, 'updated_by' => $context->user->id, 'uploaded_by' => $context->user->id, 'version_number' => '1', 'revision' => '1', 'source_format' => 'pdf', 'file_format' => 'pdf', 'source_file_path' => 'org-'.$context->organization->id.'/design-management/document.pdf', 'source_original_name' => 'document.pdf', 'source_mime_type' => 'application/pdf', 'source_size_bytes' => 100, 'status' => 'current', 'is_current' => true]);
        return [$context->user, $package, $section, $artifact, $version];
    }
}
