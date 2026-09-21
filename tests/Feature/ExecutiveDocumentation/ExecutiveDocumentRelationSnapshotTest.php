<?php

declare(strict_types=1);

namespace Tests\Feature\ExecutiveDocumentation;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentRelation;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentRelationSnapshot;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentationService;
use App\Models\Material;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ExecutiveDocumentRelationSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_target_captures_latest_version_and_keeps_missing_version_explicit(): void
    {
        [$context, $set] = $this->contextAndSet();
        $source = $this->document($context, $set, 'working_drawing_set');
        $target = $this->document($context, $set, 'hidden_work_act');
        $missing = $this->document($context, $set, 'inspection_result');
        ExecutiveDocumentRelation::query()->create([
            'organization_id' => $context->organization->id, 'document_id' => $source->id,
            'relation_type' => 'related_acts', 'target_type' => 'hidden_work_act', 'target_id' => $target->id,
        ]);
        ExecutiveDocumentRelation::query()->create([
            'organization_id' => $context->organization->id, 'document_id' => $source->id,
            'relation_type' => 'related_acts', 'target_type' => 'inspection_result', 'target_id' => $missing->id,
        ]);
        $version = $target->versions()->create(['organization_id' => $context->organization->id, 'uploaded_by' => $context->user->id, 'version_number' => '1', 'status' => 'approved', 'file_url' => 's3://target', 'content_hash' => 'hash-1', 'profile_snapshot' => [], 'basis_snapshot' => []]);

        $snapshot = app(ExecutiveDocumentRelationSnapshot::class)->forDocument($source);
        self::assertSame(['version_id' => $version->id, 'document_id' => $target->id, 'content_hash' => 'hash-1', 'status' => 'approved'], $snapshot[0]['target_version']);
        self::assertNull($snapshot[1]['target_version']);
        self::assertSame(['title' => null, 'document_date' => null, 'number' => null, 'version_number' => '1'], $snapshot[0]['document_snapshot']);
    }

    public function test_cross_project_document_target_is_rejected(): void
    {
        [$context, $set] = $this->contextAndSet();
        $source = $this->document($context, $set, 'working_drawing_set');
        $foreignProject = \App\Models\Project::factory()->create(['organization_id' => $context->organization->id]);
        $target = ExecutiveDocument::query()->create(['organization_id' => $context->organization->id, 'project_id' => $foreignProject->id, 'document_set_id' => $set->id, 'created_by' => $context->user->id, 'document_type' => 'hidden_work_act', 'title' => 'Foreign', 'status' => 'draft']);
        ExecutiveDocumentRelation::query()->create(['organization_id' => $context->organization->id, 'document_id' => $source->id, 'relation_type' => 'related_acts', 'target_type' => 'hidden_work_act', 'target_id' => $target->id]);

        $this->expectException(ValidationException::class);
        app(ExecutiveDocumentRelationSnapshot::class)->forDocument($source);
    }

    public function test_material_target_uses_domain_snapshot_without_document_version_fields(): void
    {
        [$context, $set] = $this->contextAndSet();
        $source = $this->document($context, $set, 'quality_passport');
        $material = Material::query()->create(['organization_id' => $context->organization->id, 'name' => 'Бетон', 'is_active' => true]);
        ExecutiveDocumentRelation::query()->create(['organization_id' => $context->organization->id, 'document_id' => $source->id, 'relation_type' => 'material_reference', 'target_type' => 'material', 'target_id' => $material->id]);

        $snapshot = app(ExecutiveDocumentRelationSnapshot::class)->forDocument($source)[0];
        self::assertNull($snapshot['target_version']);
        self::assertSame('Бетон', $snapshot['domain_snapshot']['name']);
    }

    public function test_add_version_freezes_relation_version_snapshot(): void
    {
        [$context, $set] = $this->contextAndSet();
        $source = $this->document($context, $set, 'working_drawing_set');
        $target = $this->document($context, $set, 'hidden_work_act');
        ExecutiveDocumentRelation::query()->create([
            'organization_id' => $context->organization->id, 'document_id' => $source->id,
            'relation_type' => 'related_acts', 'target_type' => 'hidden_work_act', 'target_id' => $target->id,
        ]);
        $v1 = $target->versions()->create(['organization_id' => $context->organization->id, 'uploaded_by' => $context->user->id, 'version_number' => '1', 'status' => 'approved', 'file_url' => 's3://target-v1', 'content_hash' => 'target-hash-1', 'profile_snapshot' => [], 'basis_snapshot' => []]);
        $service = app(ExecutiveDocumentationService::class);
        $sourceV1 = $service->addVersion($source, $context->user->id, ['version_number' => '1', 'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('source-v1.pdf', 'source-v1')]);
        $target->versions()->create(['organization_id' => $context->organization->id, 'uploaded_by' => $context->user->id, 'version_number' => '2', 'status' => 'approved', 'file_url' => 's3://target-v2', 'content_hash' => 'target-hash-2', 'profile_snapshot' => [], 'basis_snapshot' => []]);

        self::assertSame($v1->id, $sourceV1->fresh()->basis_snapshot['relations'][0]['target_version']['version_id']);
        self::assertSame('target-hash-1', $sourceV1->fresh()->basis_snapshot['relations'][0]['target_version']['content_hash']);
    }

    private function contextAndSet(): array
    {
        \Illuminate\Support\Facades\Storage::fake('s3');
        foreach ([\App\Domain\Authorization\Services\ModulePermissionChecker::class, \App\Domain\Authorization\Services\PermissionResolver::class, \App\Domain\Authorization\Services\AuthorizationService::class] as $service) {
            $this->app->forgetInstance($service);
        }
        $this->mock(\App\Modules\Core\AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = \App\Models\Project::factory()->create(['organization_id' => $context->organization->id]);
        $context->user->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true]);
        $set = ExecutiveDocumentSet::query()->create(['organization_id' => $context->organization->id, 'project_id' => $project->id, 'created_by' => $context->user->id, 'set_number' => 'REL-'.uniqid(), 'title' => 'Relations', 'status' => 'draft']);
        return [$context, $set];
    }

    private function document(AdminApiTestContext $context, ExecutiveDocumentSet $set, string $type): ExecutiveDocument
    {
        return ExecutiveDocument::query()->create(['organization_id' => $context->organization->id, 'project_id' => $set->project_id, 'document_set_id' => $set->id, 'created_by' => $context->user->id, 'document_type' => $type, 'title' => $type, 'status' => 'draft']);
    }
}
