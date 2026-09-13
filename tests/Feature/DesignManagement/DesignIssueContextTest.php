<?php

declare(strict_types=1);

namespace Tests\Feature\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignProjectIssueResource;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSet;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSetRevision;
use App\BusinessModules\Features\DesignManagement\Models\DesignIfcModelElement;
use App\Modules\Core\AccessController;
use App\BusinessModules\Features\DesignManagement\Services\DesignIssueContextResolver;
use App\BusinessModules\Features\DesignManagement\Services\DesignProjectIssueService;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class DesignIssueContextTest extends TestCase
{
    public function test_blocking_update_preserves_metadata_changed_after_issue_was_loaded(): void
    {
        [$package] = $this->versionFixture();
        $actor = User::query()->findOrFail($package->created_by);
        $issue = QualityDefect::query()->create([
            'organization_id' => $package->organization_id, 'project_id' => $package->project_id,
            'kind' => 'project', 'created_by' => $actor->id, 'defect_number' => 'PIR-ATOMIC',
            'title' => 'Проверка блокировки', 'severity' => 'major', 'status' => 'open', 'metadata' => [],
        ]);
        $issue->fresh()->update(['metadata' => ['preserved' => 'concurrent value', 'design_issue_context' => ['package_id' => $package->id]]]);
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('can')->andReturn(true);
        });
        $this->mock(AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();

        $result = app(DesignProjectIssueService::class)->setBlocking($issue, $actor, true, 'Проверить решение');

        self::assertSame('concurrent value', $result->metadata['preserved']);
        self::assertTrue($result->metadata['blocking']['active']);
        self::assertSame(1, $result->statusHistory()->count());
        self::assertSame($issue->id, $package->fresh()->metadata['completeness_invalidated_by_issue_id']);
        $this->expectException(DomainException::class);
        app(DesignProjectIssueService::class)->setBlocking($result, $actor, false, null);
    }

    public function test_issue_actions_use_permissions_separately_from_workflow_state(): void
    {
        $actor = new User;
        $actor->id = 1;
        $request = Request::create('/');
        $request->setUserResolver(static fn () => $actor);
        $issue = new QualityDefect(['organization_id' => 1, 'project_id' => 1, 'kind' => 'project', 'status' => 'open', 'metadata' => []]);
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('can')->withArgs(static fn ($actor, $permission, $scope): bool => $permission === 'design-management.review')->andReturn(false);
            $mock->shouldReceive('can')->withArgs(static fn ($actor, $permission, $scope): bool => $permission === 'design-management.issues.manage_blocking')->andReturn(true);
        });

        $data = (new DesignProjectIssueResource($issue))->toArray($request);
        $actions = array_column($data['available_actions'], 'enabled', 'key');
        self::assertFalse($actions['assign']);
        self::assertFalse($actions['resolve']);
        self::assertFalse($actions['verify']);
        self::assertTrue($actions['blocking_flag']);
    }

    public function test_version_resolves_its_canonical_package_and_ignores_supplied_snapshot_path(): void
    {
        [$package, $version] = $this->versionFixture();
        $context = app(DesignIssueContextResolver::class)->resolve(User::query()->findOrFail($package->created_by), $package->organization_id, $package->project_id, [
            'version_id' => $version->id,
            'snapshot' => ['path' => 'org-other/private.png'],
        ]);

        self::assertSame($package->id, $context['package_id']);
        self::assertSame($version->artifact_id, $context['artifact_id']);
        self::assertSame($version->id, $context['version_id']);
        self::assertArrayNotHasKey('snapshot', $context);
    }

    public function test_version_cannot_be_attached_to_another_package_in_same_project(): void
    {
        [$package, $version] = $this->versionFixture();
        $other = $package->replicate();
        $other->save();

        $this->expectException(DomainException::class);
        app(DesignIssueContextResolver::class)->resolve(User::query()->findOrFail($package->created_by), $package->organization_id, $package->project_id, [
            'package_id' => $other->id, 'version_id' => $version->id,
        ]);
    }

    public function test_version_from_another_project_is_rejected(): void
    {
        [$package, $version] = $this->versionFixture();
        $other = Project::factory()->create(['organization_id' => $package->organization_id]);
        $this->expectException(DomainException::class);
        app(DesignIssueContextResolver::class)->resolve(User::query()->findOrFail($package->created_by), $package->organization_id, $other->id, ['version_id' => $version->id]);
    }

    public function test_unknown_section_is_not_stored(): void
    {
        [$package] = $this->versionFixture();
        $this->expectException(DomainException::class);
        app(DesignIssueContextResolver::class)->resolve(User::query()->findOrFail($package->created_by), $package->organization_id, $package->project_id, [
            'package_id' => $package->id, 'section_id' => 2147483647,
        ]);
    }

    public function test_element_requires_a_concrete_model_version(): void
    {
        [$package] = $this->versionFixture();
        $this->expectException(DomainException::class);
        app(DesignIssueContextResolver::class)->resolve(User::query()->findOrFail($package->created_by), $package->organization_id, $package->project_id, ['bim_element_id' => '42']);
    }

    public function test_model_set_revision_pins_multiple_versions_and_elements(): void
    {
        [$package, $firstVersion] = $this->versionFixture();
        $actor = User::query()->findOrFail($package->created_by);
        $secondVersion = $firstVersion->replicate();
        $secondVersion->version_number = '2';
        $secondVersion->title = 'Редакция 2';
        $secondVersion->save();
        $set = DesignModelSet::query()->create([
            'organization_id' => $package->organization_id,
            'project_id' => $package->project_id,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'title' => 'Набор моделей',
            'revision' => 1,
        ]);
        $revision = DesignModelSetRevision::query()->create([
            'model_set_id' => $set->id,
            'revision' => 1,
            'version_ids' => [$firstVersion->id, $secondVersion->id],
            'transforms' => [],
            'created_by' => $actor->id,
        ]);
        foreach ([[$firstVersion->id, 101], [$secondVersion->id, 202]] as [$versionId, $expressId]) {
            DesignIfcModelElement::query()->create([
                'organization_id' => $package->organization_id,
                'project_id' => $package->project_id,
                'version_id' => $versionId,
                'express_id' => $expressId,
            ]);
        }
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnTrue();
        $this->mock(AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();

        $context = app(DesignIssueContextResolver::class)->resolve($actor, $package->organization_id, $package->project_id, [
            'model_set_revision_id' => $revision->id,
            'elements' => [
                ['version_id' => $firstVersion->id, 'element_id' => 101],
                ['version_id' => $secondVersion->id, 'element_id' => 202],
            ],
        ]);

        self::assertSame($revision->id, $context['model_set_revision_id']);
        self::assertSame([
            ['version_id' => $firstVersion->id, 'element_id' => 101],
            ['version_id' => $secondVersion->id, 'element_id' => 202],
        ], $context['elements']);

        $cameraOnly = app(DesignIssueContextResolver::class)->resolve($actor, $package->organization_id, $package->project_id, [
            'model_set_revision_id' => $revision->id,
            'camera' => ['position' => [1, 2, 3]],
        ]);
        self::assertSame($revision->id, $cameraOnly['model_set_revision_id']);
        self::assertArrayNotHasKey('elements', $cameraOnly);
    }

    private function versionFixture(): array
    {
        $actor = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        DB::table('project_user')->insert(['project_id' => $project->id, 'user_id' => $actor->user->id, 'role' => 'project_manager', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $scope = ['organization_id' => $actor->organization->id, 'project_id' => $project->id,
            'created_by' => $actor->user->id, 'updated_by' => $actor->user->id];
        $package = DesignPackage::query()->create($scope + ['title' => 'Комплект АР', 'project_stage' => 'pd', 'status' => 'draft']);
        $artifact = DesignArtifact::query()->create($scope + ['package_id' => $package->id, 'title' => 'Модель АР', 'artifact_type' => 'model']);
        $version = DesignArtifactVersion::query()->create($scope + ['artifact_id' => $artifact->id, 'uploaded_by' => $actor->user->id,
            'version_number' => '1', 'title' => 'Редакция 1', 'source_file_path' => "org-{$actor->organization->id}/model.ifc",
            'source_original_name' => 'model.ifc', 'source_mime_type' => 'application/octet-stream', 'source_size_bytes' => 100]);

        return [$package, $version];
    }
}
