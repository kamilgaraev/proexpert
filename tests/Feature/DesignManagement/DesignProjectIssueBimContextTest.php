<?php

declare(strict_types=1);

namespace Tests\Feature\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignIfcModelElement;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSet;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSetRevision;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Services\DesignProjectIssueService;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Modules\Core\AccessController;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class DesignProjectIssueBimContextTest extends TestCase
{
    public function test_independent_view_snapshot_restores_exact_versions_and_transforms(): void
    {
        [$actor, $project, $version] = $this->fixture();
        $second = $version->replicate();
        $second->version_number = '2'; $second->save();
        $this->allowProjectAccess();
        $models = [
            ['version_id' => $second->id, 'transform' => ['shift' => [10, 20, 30], 'rotation' => 45]],
            ['version_id' => $version->id, 'transform' => ['shift' => [0, 0, 0], 'rotation' => 0]],
        ];
        $resolver = app(\App\BusinessModules\Features\DesignManagement\Services\DesignIssueContextResolver::class);
        $context = $resolver->resolve($actor->user, $actor->organization->id, $project->id, [
            'version_id' => $version->id, 'view_models' => $models, 'camera' => ['position' => [1, 2, 3]],
        ]);
        $issue = QualityDefect::query()->create([
            'organization_id' => $actor->organization->id, 'project_id' => $project->id,
            'kind' => 'project', 'created_by' => $actor->user->id, 'defect_number' => 'PIR-VIEW',
            'title' => 'Снимок просмотра', 'severity' => 'major', 'status' => 'open',
            'metadata' => ['design_issue_context' => $context],
        ]);
        $payload = app(DesignProjectIssueService::class)->bimContext($issue->fresh(), $actor->user);
        self::assertSame([$second->id, $version->id], $payload['models']);
        self::assertSame(['shift' => [10.0, 20.0, 30.0], 'rotation' => 45.0], $payload['transforms'][$second->id]);
        self::assertSame(['position' => [1, 2, 3]], $payload['camera']);
        self::assertNull($payload['model_set_revision_id']);
        self::assertSame(0, DesignModelSet::query()->count());
        $second->update(['file_format' => 'pdf']);
        $this->expectException(DomainException::class);
        app(DesignProjectIssueService::class)->bimContext($issue->fresh(), $actor->user);
    }

    public function test_independent_view_rejects_invalid_transforms_duplicates_and_foreign_models(): void
    {
        [$actor, $project, $version] = $this->fixture();
        [, , $foreign] = $this->fixture();
        $this->allowProjectAccess();
        $valid = ['version_id' => $version->id, 'transform' => ['shift' => [0, 0, 0], 'rotation' => 0]];
        $invalid = [
            [], [$valid, $valid],
            [['version_id' => $foreign->id, 'transform' => $valid['transform']]],
            [['version_id' => $version->id, 'transform' => ['shift' => [0, 0], 'rotation' => 0]]],
            [['version_id' => $version->id, 'transform' => ['shift' => [0, INF, 0], 'rotation' => 0]]],
        ];
        $resolver = app(\App\BusinessModules\Features\DesignManagement\Services\DesignIssueContextResolver::class);
        foreach ($invalid as $models) {
            try {
                $resolver->resolve($actor->user, $actor->organization->id, $project->id, ['view_models' => $models]);
                self::fail('Invalid model snapshot was accepted');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(DomainException::class);
        $resolver->resolve($actor->user, $actor->organization->id, $project->id, ['view_models' => [$valid], 'model_set_revision_id' => 1]);
    }

    public function test_pinned_revision_survives_current_version_change_without_mutating_issue_or_sessions(): void
    {
        [$actor, $project, $initial] = $this->fixture();
        $initial->update(['is_current' => false]);
        $old = $initial->replicate();
        $old->version_number = '2'; $old->is_current = true; $old->save();
        self::assertNotSame($old->artifact_id, $old->id);
        $new = $old->replicate();
        $new->version_number = '3'; $new->is_current = true; $new->save();
        $old->update(['is_current' => false]);
        $set = DesignModelSet::query()->create(['organization_id' => $actor->organization->id, 'project_id' => $project->id, 'created_by' => $actor->user->id, 'updated_by' => $actor->user->id, 'title' => 'Pinned', 'revision' => 1]);
        $revision = DesignModelSetRevision::query()->create(['model_set_id' => $set->id, 'revision' => 1, 'version_ids' => [$old->id], 'transforms' => ['x' => 12], 'created_by' => $actor->user->id]);
        DesignIfcModelElement::query()->create(['organization_id' => $actor->organization->id, 'project_id' => $project->id, 'version_id' => $old->id, 'express_id' => 10]);
        $issue = QualityDefect::query()->create(['organization_id' => $actor->organization->id, 'project_id' => $project->id, 'kind' => 'project', 'created_by' => $actor->user->id, 'defect_number' => 'PIR-CONTEXT', 'title' => 'Issue', 'severity' => 'major', 'status' => 'open', 'inspection_required' => false, 'metadata' => ['design_issue_context' => ['model_set_revision_id' => $revision->id, 'camera' => ['x' => 1], 'elements' => [['version_id' => $old->id, 'element_id' => 10]]]]]);
        $this->allowProjectAccess();
        $sessionsBefore = \App\BusinessModules\Features\DesignManagement\Models\DesignModelSession::query()->count();
        $updatedAt = $issue->updated_at?->toIso8601String();

        $payload = app(DesignProjectIssueService::class)->bimContext($issue, $actor->user);

        self::assertSame([$old->id], $payload['models']);
        self::assertSame($old->id, $payload['model_versions'][0]['version_id']);
        self::assertSame($old->artifact_id, $payload['model_versions'][0]['model_id']);
        self::assertSame($old->artifact->package_id, $payload['model_versions'][0]['package_id']);
        self::assertSame(['x' => 12], $payload['transforms']);
        self::assertSame(['x' => 1], $payload['camera']);
        self::assertSame([['version_id' => $old->id, 'element_id' => 10]], $payload['elements']);
        self::assertSame($sessionsBefore, \App\BusinessModules\Features\DesignManagement\Models\DesignModelSession::query()->count());
        self::assertSame($updatedAt, $issue->fresh()->updated_at?->toIso8601String());
    }

    public function test_pdf_version_is_rejected(): void
    {
        [$actor, $project, $version] = $this->fixture();
        $version->update(['file_format' => 'pdf']);
        $issue = QualityDefect::query()->create(['organization_id' => $actor->organization->id, 'project_id' => $project->id, 'kind' => 'project', 'created_by' => $actor->user->id, 'defect_number' => 'PIR-PDF', 'title' => 'Issue', 'severity' => 'major', 'status' => 'open', 'inspection_required' => false, 'metadata' => ['design_issue_context' => ['version_id' => $version->id]]]);
        $this->allowProjectAccess();
        $this->expectException(DomainException::class);
        app(DesignProjectIssueService::class)->bimContext($issue, $actor->user);
    }

    public function test_project_access_is_rechecked(): void
    {
        [$actor, $project, $version] = $this->fixture();
        $issue = QualityDefect::query()->create(['organization_id' => $actor->organization->id, 'project_id' => $project->id, 'kind' => 'project', 'created_by' => $actor->user->id, 'defect_number' => 'PIR-DENIED', 'title' => 'Issue', 'severity' => 'major', 'status' => 'open', 'inspection_required' => false, 'metadata' => ['design_issue_context' => ['version_id' => $version->id]]]);
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnTrue();
        $this->mock(AccessController::class)->shouldReceive('hasModuleAccess')->andReturnFalse();
        $this->expectException(DomainException::class);
        app(DesignProjectIssueService::class)->bimContext($issue, $actor->user);
    }

    private function fixture(): array
    {
        $actor = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        DB::table('project_user')->insert(['project_id' => $project->id, 'user_id' => $actor->user->id, 'role' => 'project_manager', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $package = DesignPackage::query()->create(['organization_id' => $actor->organization->id, 'project_id' => $project->id, 'created_by' => $actor->user->id, 'updated_by' => $actor->user->id, 'title' => 'Комплект', 'project_stage' => 'pd', 'status' => 'draft']);
        $artifact = DesignArtifact::query()->create(['organization_id' => $actor->organization->id, 'project_id' => $project->id, 'package_id' => $package->id, 'created_by' => $actor->user->id, 'updated_by' => $actor->user->id, 'title' => 'Model', 'artifact_type' => 'model']);
        $version = DesignArtifactVersion::query()->create(['organization_id' => $actor->organization->id, 'project_id' => $project->id, 'artifact_id' => $artifact->id, 'created_by' => $actor->user->id, 'updated_by' => $actor->user->id, 'uploaded_by' => $actor->user->id, 'title' => 'Old', 'version_number' => '1', 'source_file_path' => "org-{$actor->organization->id}/old.ifc", 'source_original_name' => 'old.ifc', 'source_mime_type' => 'application/octet-stream', 'source_size_bytes' => 1, 'file_format' => 'ifc', 'is_current' => true]);
        return [$actor, $project, $version];
    }

    private function allowProjectAccess(): void
    {
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnTrue();
        $this->mock(AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();
    }
}
