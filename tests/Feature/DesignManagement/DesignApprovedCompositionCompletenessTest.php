<?php

declare(strict_types=1);

namespace Tests\Feature\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Models\DesignCompositionExclusion;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignCompositionRevision;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use App\BusinessModules\Features\DesignManagement\Support\Rules\RequiredCurrentArtifactRule;
use App\BusinessModules\Features\DesignManagement\Support\Rules\RequiredSectionRule;
use App\BusinessModules\Features\DesignManagement\Support\Rules\AllowedFileFormatRule;
use App\BusinessModules\Features\DesignManagement\Support\Rules\SheetRegistryRule;
use App\BusinessModules\Features\DesignManagement\Support\Rules\RevisionSequenceRule;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Models\Project;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class DesignApprovedCompositionCompletenessTest extends TestCase
{
    public static function stages(): array
    {
        return [['pd', 'sections'], ['rd', 'document_groups'], ['survey', 'items'], ['bim', 'items']];
    }

    #[DataProvider('stages')]
    public function test_document_checks_use_approved_requirements_and_ignore_historical_and_excluded_documents(string $stage, string $itemsKey): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $attributes = ['organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $context->user->id, 'updated_by' => $context->user->id];
        $package = DesignPackage::query()->create($attributes + ['title' => 'Утверждённый состав', 'project_stage' => $stage, 'status' => 'draft']);
        $selected = null;
        $selectedVersion = null;

        foreach (['AR' => ['AR-01', 'AR-OLD'], 'HISTORY' => ['HISTORY-01'], 'EXCLUDED' => ['EXCLUDED-01']] as $code => $documents) {
            $section = DesignPackageSection::query()->create($attributes + ['package_id' => $package->id, 'code' => $code, 'title' => $code, 'project_stage' => $stage, 'required' => true, 'metadata' => ['documents' => array_map(static fn (string $document): array => ['document_code' => $document, 'allowed_formats' => ['dwg'], 'sheet_registry_required' => true], $documents)]]);
            foreach ($documents as $documentCode) {
                $current = $documentCode === 'AR-01';
                $artifact = DesignArtifact::query()->create($attributes + ['package_id' => $package->id, 'section_id' => $section->id, 'document_code' => $documentCode, 'title' => $documentCode, 'artifact_type' => 'text_document', 'requires_sheet_registry' => true]);
                $version = DesignArtifactVersion::query()->create($attributes + ['artifact_id' => $artifact->id, 'uploaded_by' => $context->user->id, 'title' => $documentCode, 'version_number' => '1', 'revision' => $current ? 'A' : null, 'source_file_path' => 'test/document.pdf', 'source_original_name' => 'document.pdf', 'source_mime_type' => 'application/pdf', 'source_size_bytes' => 1, 'file_format' => 'pdf', 'is_current' => true]);
                if ($current) {
                    $selected = $artifact;
                    $selectedVersion = $version;
                }
            }
        }

        $revision = DesignCompositionRevision::query()->create($attributes + ['package_id' => $package->id, 'revision_number' => 1, 'status' => 'approved', 'composition' => ['project_stage' => $stage, $itemsKey => [['code' => 'AR', 'required' => true, 'documents' => [['document_code' => 'AR-01', 'allowed_formats' => ['pdf'], 'sheet_registry_required' => false]]], ['code' => 'EXCLUDED', 'required' => true]]], 'fingerprint' => 'selected-documents', 'approved_by' => $context->user->id, 'approved_at' => now()]);
        DesignCompositionExclusion::query()->create($attributes + ['package_id' => $package->id, 'revision_id' => $revision->id, 'item_key' => 'EXCLUDED', 'reason' => 'Не входит в выпуск']);
        $package->update(['composition_revision_id' => $revision->id, 'composition_status' => 'approved']);
        $relations = ['sections.artifacts.currentVersion.sheets', 'artifacts.currentVersion'];
        $loaded = $package->fresh($relations);
        $format = new AllowedFileFormatRule();
        $sheets = new SheetRegistryRule();
        $sequence = new RevisionSequenceRule();

        self::assertSame([], $format->check($loaded));
        self::assertSame([], $sheets->check($loaded));
        self::assertSame([], $sequence->check($loaded));

        $next = $revision->replicate();
        $composition = $revision->composition;
        $composition[$itemsKey][0]['documents'][0]['sheet_registry_required'] = true;
        $next->fill(['revision_number' => 2, 'composition' => $composition, 'fingerprint' => 'selected-documents-with-sheets']);
        $next->save();
        DesignCompositionExclusion::query()->create($attributes + ['package_id' => $package->id, 'revision_id' => $next->id, 'item_key' => 'EXCLUDED', 'reason' => 'Не входит в выпуск']);
        $package->update(['composition_revision_id' => $next->id]);
        $selectedVersion->update(['file_format' => 'dwg', 'revision' => null]);
        $loaded = $package->fresh($relations);
        $formatResults = $format->check($loaded);
        $sheetResults = $sheets->check($loaded);
        $revisionResults = $sequence->check($loaded);
        self::assertCount(1, $formatResults);
        self::assertSame($selected->id, $formatResults[0]->targetId);
        self::assertCount(1, $sheetResults);
        self::assertSame($selectedVersion->id, $sheetResults[0]->targetId);
        self::assertCount(1, $revisionResults);
        self::assertSame($selectedVersion->id, $revisionResults[0]->targetId);
        $selectedVersion->update(['is_current' => false]);
        $sectionResults = (new RequiredSectionRule())->check($package->fresh($relations));
        self::assertCount(1, $sectionResults);
        self::assertSame('AR', $sectionResults[0]->metadata['section_code']);
    }

    public function test_historical_section_and_excluded_selected_section_do_not_block_but_required_document_does(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $package = DesignPackage::query()->create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $context->user->id, 'updated_by' => $context->user->id, 'title' => 'Состав', 'project_stage' => 'pd', 'status' => 'draft', 'metadata' => []]);
        DesignPackageSection::query()->create(['organization_id' => $package->organization_id, 'project_id' => $project->id, 'package_id' => $package->id, 'code' => 'HISTORY', 'title' => 'Исторический', 'project_stage' => 'pd', 'required' => true, 'metadata' => []]);
        $revision = DesignCompositionRevision::query()->create(['organization_id' => $package->organization_id, 'project_id' => $project->id, 'package_id' => $package->id, 'revision_number' => 1, 'status' => 'approved', 'composition' => ['project_stage' => 'pd', 'sections' => [['code' => 'AR', 'required' => true, 'documents' => [['document_code' => 'AR-01', 'required' => true]]], ['code' => 'EXCLUDED', 'required' => true]]], 'fingerprint' => 'approved', 'created_by' => $context->user->id, 'approved_by' => $context->user->id, 'approved_at' => now()]);
        DesignCompositionExclusion::query()->create(['organization_id' => $package->organization_id, 'project_id' => $project->id, 'package_id' => $package->id, 'revision_id' => $revision->id, 'item_key' => 'EXCLUDED', 'reason' => 'Не требуется', 'created_by' => $context->user->id]);
        $package->update(['composition_revision_id' => $revision->id, 'composition_status' => 'approved']);
        DesignPackageSection::query()->create(['organization_id' => $package->organization_id, 'project_id' => $project->id, 'package_id' => $package->id, 'code' => 'AR', 'title' => 'АР', 'project_stage' => 'pd', 'required' => true, 'metadata' => []]);
        $loaded = $package->fresh(['sections.artifacts.currentVersion']);

        $sectionFindings = (new RequiredSectionRule())->check($loaded);
        $this->assertCount(1, $sectionFindings);
        $this->assertSame('AR', $sectionFindings[0]->metadata['section_code']);
        $findings = (new RequiredCurrentArtifactRule())->check($loaded);
        $this->assertCount(1, $findings);
        $this->assertSame('AR-01', $findings[0]->metadata['document_code']);
    }
}
