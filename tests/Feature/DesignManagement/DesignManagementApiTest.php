<?php

declare(strict_types=1);

namespace Tests\Feature\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Jobs\PrepareDesignModelViewerJob;
use App\BusinessModules\Features\DesignManagement\Events\DesignModelSessionTransientEvent;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignDocumentSheet;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Models\DesignIfcModelElement;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSession;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSet;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use App\BusinessModules\Features\DesignManagement\Models\DesignReviewComment;
use App\BusinessModules\Features\DesignManagement\Models\DesignSourceLink;
use App\BusinessModules\Features\DesignManagement\Models\DesignWorkflowEvent;
use App\BusinessModules\Features\DesignManagement\Services\Contracts\DesignModelMultipartUploader;
use App\BusinessModules\Features\DesignManagement\Services\DesignManagementService;
use App\BusinessModules\Features\DesignManagement\Services\DesignSourceLinkService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Storage\FileService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class DesignManagementApiTest extends TestCase
{
    public function test_bim_and_issues_respect_the_common_project_access_mode(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id, 'is_archived' => false]);
        $foreign = Project::factory()->create(['is_archived' => false]);
        $this->allowAdminAccess();
        $this->mock(AccessController::class)->shouldReceive('hasModuleAccess')->andReturn(true);
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'all_projects']);
        $this->withHeaders($context->authHeaders())->getJson("/api/v1/admin/design-management/model-sets?project_id={$project->id}")->assertOk();
        $this->withHeaders($context->authHeaders())->getJson("/api/v1/admin/design-management/projects/{$project->id}/issues")->assertOk();
        $access = app(\App\BusinessModules\Features\DesignManagement\Services\DesignModelSessionAccessService::class);
        $this->assertFalse($access->canAccessProject($context->user, $context->organization->id, $foreign->id));
        $project->update(['is_archived' => true]);
        $this->assertFalse($access->canAccessProject($context->user, $context->organization->id, $project->id));
        $project->update(['is_archived' => false]);
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'assigned_projects']);
        $this->withHeaders($context->authHeaders())->getJson("/api/v1/admin/design-management/model-sets?project_id={$project->id}")->assertForbidden();
        $this->attachProjectUser($project, $context->user);
        $this->assertTrue($access->canAccessProject($context->user, $context->organization->id, $project->id));
        $this->allowAdminAccess(['design-management.models.view']);
        $this->assertFalse(app(\App\BusinessModules\Features\DesignManagement\Services\DesignModelSessionAccessService::class)->canAccessProject($context->user, $context->organization->id, $project->id));
    }

    public static function issueInterfaceSubscriptions(): array
    {
        return [
            'PIR only' => [true, false, []],
            'quality only' => [false, true, []],
            'both modules' => [true, true, []],
            'neither module' => [false, false, []],
            'PIR read denied' => [true, true, ['design-management.view']],
            'quality read denied' => [true, true, ['quality-control.view']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('issueInterfaceSubscriptions')]
    public function test_project_issue_interfaces_respect_subscriptions_permissions_and_the_common_id(bool $pir, bool $quality, array $denied): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->attachProjectUser($project, $context->user);
        $this->allowAdminAccess($denied);
        $this->mock(AccessController::class)->shouldReceive('hasModuleAccess')->andReturnUsing(
            static fn (int $organizationId, string $module): bool => match ($module) {
                'design-management' => $pir,
                'quality-control' => $quality,
                default => true,
            },
        );
        $issues = [];
        foreach (['project', 'construction'] as $kind) {
            $issues[$kind] = \App\BusinessModules\Features\QualityControl\Models\QualityDefect::query()->create([
                'organization_id' => $context->organization->id, 'project_id' => $project->id,
                'kind' => $kind, 'created_by' => $context->user->id, 'defect_number' => 'INTERFACE-'.$kind,
                'title' => 'Замечание '.$kind, 'severity' => 'major', 'status' => 'open', 'metadata' => [],
            ]);
        }
        $pirReadable = $pir && ! in_array('design-management.view', $denied, true);
        $qualityReadable = $quality && ! in_array('quality-control.view', $denied, true);
        $pirList = $this->withHeaders($context->authHeaders())->getJson("/api/v1/admin/design-management/projects/{$project->id}/issues");
        $pirList->assertStatus($pirReadable ? 200 : 403);
        if ($pirReadable) {
            $pirList->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $issues['project']->id);
        }
        foreach ($issues as $kind => $issue) {
            $pirResponse = $this->withHeaders($context->authHeaders())->getJson("/api/v1/admin/design-management/issues/{$issue->id}");
            $pirResponse->assertStatus($pirReadable ? ($kind === 'project' ? 200 : 404) : 403);
            if ($pirReadable && $kind === 'project') {
                $pirResponse->assertJsonPath('data.id', $issue->id);
            }
            $qualityResponse = $this->withHeaders($context->authHeaders())->getJson("/api/v1/admin/quality-control/defects/{$issue->id}");
            $qualityResponse->assertStatus($qualityReadable ? 200 : 403);
            if ($qualityReadable) {
                $qualityResponse->assertJsonPath('data.id', $issue->id);
            }
        }
        $this->assertSame(2, \App\BusinessModules\Features\QualityControl\Models\QualityDefect::query()->where('project_id', $project->id)->count());
    }

    public function test_link_context_opens_the_exact_historical_source_and_rechecks_access(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess(['workflow-management']);
        $package = DesignPackage::query()->findOrFail($this->createPackage($context, $project));
        $source = $this->storedVersion($package, $context->user);
        $source->update(['is_current' => false]);
        $work = \App\Models\CompletedWork::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'user_id' => $context->user->id, 'quantity' => 3, 'completed_quantity' => 3,
            'price' => 500, 'total_amount' => 1500, 'completion_date' => '2026-09-09',
            'description' => 'Связанная работа', 'status' => 'confirmed',
        ]);
        $element = DesignIfcModelElement::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'version_id' => $source->id, 'express_id' => 42, 'name' => 'Прежняя стена',
        ]);
        $service = app(DesignSourceLinkService::class);
        $link = $service->create($context->user, $context->organization->id, [
            'source_version_id' => $source->id, 'source_element_id' => 42,
            'target_type' => 'completed_work', 'target_id' => $work->id,
        ]);
        $url = "/api/v1/admin/design-management/source-links/{$link['id']}/context";
        $this->withHeaders($context->authHeaders())->getJson($url)->assertOk()
            ->assertJsonPath('data.version_id', $source->id)->assertJsonPath('data.kind', 'model')
            ->assertJsonPath('data.element.express_id', 42)->assertJsonPath('data.target_id', $work->id);
        $service->delete($context->user, $context->organization->id, $link['id'], 'Сохранить историю', 1);
        $this->withHeaders($context->authHeaders())->getJson($url)->assertOk()->assertJsonPath('data.version_id', $source->id);
        $element->delete();
        $this->withHeaders($context->authHeaders())->getJson($url)->assertStatus(422);

        $document = $this->storedVersion($package, $context->user);
        $document->update(['source_format' => 'pdf', 'file_format' => 'pdf', 'source_original_name' => 'old.pdf', 'source_mime_type' => 'application/pdf', 'is_current' => false]);
        $sheet = DesignDocumentSheet::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'package_id' => $package->id, 'artifact_id' => $document->artifact_id,
            'version_id' => $document->id, 'sheet_number' => 'АР-7', 'sheet_title' => 'План первого этажа',
        ]);
        $documentLink = $service->create($context->user, $context->organization->id, [
            'source_version_id' => $document->id, 'source_sheet_id' => $sheet->id,
            'target_type' => 'completed_work', 'target_id' => $work->id,
        ]);
        $documentUrl = "/api/v1/admin/design-management/source-links/{$documentLink['id']}/context";
        $this->withHeaders($context->authHeaders())->getJson($documentUrl)->assertOk()
            ->assertJsonPath('data.version_id', $document->id)->assertJsonPath('data.kind', 'document')
            ->assertJsonPath('data.sheet.sheet_number', 'АР-7')->assertJsonPath('data.filename', 'old.pdf');
        $this->mock(AuthorizationService::class, static function (MockInterface $mock): void {
            $mock->shouldReceive('can')->andReturnUsing(static fn (User $actor, string $permission): bool => $permission !== 'design-management.documents.view');
        });
        $restrictedService = new DesignSourceLinkService(app(AuthorizationService::class), app(AccessController::class));
        try {
            $restrictedService->sourceContext($context->user, $context->organization->id, $documentLink['id']);
            $this->fail('The document viewing permission must be checked separately');
        } catch (\DomainException $exception) {
            $this->assertSame(trans_message('design_links.errors.forbidden'), $exception->getMessage());
        }
        $this->allowAdminAccess();
        $this->mock(AccessController::class, static function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturnUsing(static fn (int $organizationId, string $module): bool => $module !== 'design-management');
        });
        $inactiveService = new DesignSourceLinkService(app(AuthorizationService::class), app(AccessController::class));
        try {
            $inactiveService->sourceContext($context->user, $context->organization->id, $documentLink['id']);
            $this->fail('PIR access must be checked when opening a linked source');
        } catch (\DomainException $exception) {
            $this->assertSame(trans_message('design_links.errors.pir_inactive'), $exception->getMessage());
        }
    }

    public function test_presence_auth_signs_the_real_user_and_rechecks_the_entire_model_scope(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $otherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->attachProjectUser($project, $context->user);
        $this->allowAdminAccess();
        $pirAvailable = true;
        $this->mock(AccessController::class)->shouldReceive('hasModuleAccess')->andReturnUsing(static function () use (&$pirAvailable): bool { return $pirAvailable; });
        $package = DesignPackage::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'created_by' => $context->user->id, 'updated_by' => $context->user->id,
            'title' => 'АР', 'status' => 'draft', 'metadata' => [],
        ]);
        $version = $this->storedVersion($package, $context->user);
        $version->update(['file_format' => 'ifc']);
        $service = app(\App\BusinessModules\Features\DesignManagement\Services\DesignModelSetService::class);
        $set = $service->create($context->organization->id, $context->user, ['project_id' => $project->id, 'title' => 'Сеанс', 'version_ids' => [$version->id]]);
        $session = $service->createSession($context->organization->id, $context->user, ['project_id' => $project->id, 'title' => 'Проверка', 'model_set_id' => $set->id, 'model_set_revision' => 1]);
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb' => [
            'driver' => 'reverb', 'key' => 'bim-auth-test', 'secret' => 'bim-auth-secret', 'app_id' => 'bim-auth-test',
            'options' => ['host' => '127.0.0.1', 'port' => 18079, 'scheme' => 'http', 'useTLS' => false],
        ]]);
        \Illuminate\Support\Facades\Broadcast::purge('reverb');
        require base_path('routes/channels.php');
        $url = '/api/v1/admin/broadcasting/auth';
        $payload = ['socket_id' => '123.456', 'channel_name' => 'presence-design-model-session.'.$session->id, 'user_id' => 999999];
        $response = $this->postJson($url, $payload, $context->authHeaders())->assertOk();
        $data = json_decode($response->json('channel_data'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame((string) $context->user->id, (string) $data['user_id']);
        $this->assertSame($context->user->id, $data['user_info']['id']);
        $this->assertSame('bim-auth-test:'.hash_hmac('sha256', '123.456:'.$payload['channel_name'].':'.$response->json('channel_data'), 'bim-auth-secret'), $response->json('auth'));
        $pirAvailable = false;
        $this->postJson($url, $payload, $context->authHeaders())->assertForbidden();
        $pirAvailable = true;
        $package->update(['project_id' => $otherProject->id]);
        $this->postJson($url, $payload, $context->authHeaders())->assertForbidden();
        $package->update(['project_id' => $project->id]);
        $version->update(['file_format' => 'pdf']);
        $this->postJson($url, $payload, $context->authHeaders())->assertForbidden();
        $version->update(['file_format' => 'ifc']);
        $project->users()->updateExistingPivot($context->user->id, ['is_active' => false]);
        $this->postJson($url, $payload, $context->authHeaders())->assertForbidden();
    }

    public function test_project_issue_http_preserves_independent_model_snapshot(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->attachProjectUser($project, $context->user);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $models = [];
        foreach (['Архитектура', 'Конструкции'] as $title) {
            $package = DesignPackage::query()->create([
                'organization_id' => $context->organization->id, 'project_id' => $project->id,
                'created_by' => $context->user->id, 'updated_by' => $context->user->id,
                'title' => $title, 'status' => 'draft', 'metadata' => [],
            ]);
            $version = $this->storedVersion($package, $context->user);
            $version->update(['file_format' => 'ifc']);
            $models[] = ['version_id' => $version->id, 'transform' => ['shift' => [10, 20, 30], 'rotation' => 45]];
        }
        $url = '/api/v1/admin/design-management/projects/'.$project->id.'/issues';
        $payload = ['title' => 'Проверить совмещение', 'severity' => 'major', 'version_id' => $models[0]['version_id'], 'view_models' => $models, 'point' => ['x' => 12.5, 'y' => -3, 'z' => 0]];
        $response = $this->postJson($url, $payload, $context->authHeaders())->assertCreated()
            ->assertJsonCount(2, 'data.context.view_models')
            ->assertJsonPath('data.context.view_models.1.version_id', $models[1]['version_id']);
        $id = $response->json('data.id');
        $this->getJson('/api/v1/admin/design-management/issues/'.$id.'/bim-context', $context->authHeaders())->assertOk()
            ->assertJsonPath('data.models', array_column($models, 'version_id'))
            ->assertJsonPath('data.transforms.'.$models[1]['version_id'].'.shift', [10, 20, 30])
            ->assertJsonPath('data.point', ['x' => 12.5, 'y' => -3, 'z' => 0])
            ->assertJsonPath('data.transforms.'.$models[1]['version_id'].'.rotation', 45);
        $this->postJson($url, array_replace($payload, ['point' => ['x' => 1, 'y' => 2]]), $context->authHeaders())->assertUnprocessable();
        $this->postJson($url, array_replace($payload, ['point' => ['x' => 'not-a-number', 'y' => 2, 'z' => 3]]), $context->authHeaders())->assertUnprocessable();
        $this->postJson($url, array_replace($payload, ['view_models' => [$models[0], $models[0]]]), $context->authHeaders())->assertUnprocessable();
        $models[0]['transform']['shift'] = [10, 20];
        $this->postJson($url, array_replace($payload, ['view_models' => $models]), $context->authHeaders())->assertUnprocessable();
        $this->assertSame(1, \App\BusinessModules\Features\QualityControl\Models\QualityDefect::query()->where('project_id', $project->id)->count());
    }

    public function test_saved_model_set_opens_exact_revision_without_creating_a_session(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->attachProjectUser($project, $context->user);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $versions = [];
        foreach (['АР', 'КР'] as $title) {
            $package = DesignPackage::query()->create([
                'organization_id' => $context->organization->id, 'project_id' => $project->id,
                'created_by' => $context->user->id, 'updated_by' => $context->user->id,
                'title' => $title, 'status' => 'draft', 'metadata' => [],
            ]);
            $version = $this->storedVersion($package, $context->user);
            $version->update(['file_format' => 'ifc']);
            $versions[] = $version;
        }
        $service = app(\App\BusinessModules\Features\DesignManagement\Services\DesignModelSetService::class);
        $ids = [$versions[1]->id, $versions[0]->id];
        $transforms = [(string) $ids[0] => ['shift' => [10, 20, 30], 'rotation' => 45]];
        $set = $service->create($context->organization->id, $context->user, [
            'project_id' => $project->id, 'title' => 'Сводная модель', 'version_ids' => $ids, 'transforms' => $transforms,
        ]);
        $service->update($context->organization->id, $set->id, $context->user, [
            'expected_revision' => 1, 'version_ids' => [$ids[1]], 'transforms' => [],
        ]);
        $url = '/api/v1/admin/design-management/model-sets/'.$set->id.'/revisions/1/view';
        $sessions = DesignModelSession::query()->count();
        $this->getJson($url, $context->authHeaders())->assertOk()
            ->assertJsonPath('data.model_set_revision', 1)
            ->assertJsonPath('data.models', $ids)->assertJsonCount(2, 'data.model_versions')
            ->assertJsonPath('data.model_versions.0.version_id', $ids[0])
            ->assertJsonPath('data.model_versions.0.package_id', $versions[1]->artifact->package_id)
            ->assertJsonPath('data.transforms.'.$ids[0].'.rotation', 45)
            ->assertJsonPath('data.transforms.'.$ids[0].'.shift', [10, 20, 30]);
        $this->assertSame($sessions, DesignModelSession::query()->count());
        $this->getJson(str_replace('/1/view', '/99/view', $url), $context->authHeaders())->assertNotFound();
        $versions[0]->update(['file_format' => 'pdf']);
        $this->getJson($url, $context->authHeaders())->assertForbidden();
        $versions[0]->update(['file_format' => 'ifc']);
        $project->users()->updateExistingPivot($context->user->id, ['is_active' => false]);
        $this->getJson($url, $context->authHeaders())->assertForbidden();
    }

    public function test_project_model_catalog_spans_packages_and_excludes_other_projects(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $otherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->attachProjectUser($project, $context->user);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $packages = [];
        foreach ([[$project, 'Архитектура'], [$project, 'Конструкции'], [$otherProject, 'Другой проект']] as [$owner, $title]) {
            $packages[] = DesignPackage::query()->create([
                'organization_id' => $context->organization->id, 'project_id' => $owner->id,
                'created_by' => $context->user->id, 'updated_by' => $context->user->id,
                'title' => $title, 'status' => 'draft', 'metadata' => [],
            ]);
        }
        $ids = [];
        foreach (range(1, 28) as $number) {
            $package = $number <= 26 ? $packages[0] : ($number === 27 ? $packages[1] : $packages[2]);
            $version = $this->storedVersion($package, $context->user);
            $version->update(['file_format' => 'ifc']);
            $ids[] = $version->id;
            DesignModelDerivative::query()->create([
                'organization_id' => $context->organization->id, 'project_id' => $package->project_id,
                'version_id' => $version->id, 'created_by' => $context->user->id, 'updated_by' => $context->user->id,
                'status' => 'ready', 'derivative_file_path' => 'catalog/example.frag',
            ]);
        }
        $unprepared = $this->storedVersion($packages[1], $context->user);
        $unprepared->update(['file_format' => 'ifc']);
        $this->storedVersion($packages[1], $context->user);
        $base = '/api/v1/admin/design-management/project-model-versions';
        $this->getJson($base.'?project_id='.$project->id, $context->authHeaders())
            ->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.total', 27)
            ->assertJsonPath('data.0.package_id', $packages[1]->id)->assertJsonPath('data.0.package_title', 'Конструкции');
        $this->getJson($base.'?project_id='.$project->id.'&page=2', $context->authHeaders())
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.id', $ids[0]);
        $this->getJson($base.'?project_id='.$project->id.'&search='.urlencode('Конструкции'), $context->authHeaders())
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ids[26]);
        $this->getJson($base.'?project_id='.$otherProject->id, $context->authHeaders())->assertForbidden();
        $project->users()->updateExistingPivot($context->user->id, ['is_active' => false]);
        $this->getJson($base.'?project_id='.$project->id, $context->authHeaders())->assertForbidden();
    }

    public function test_model_set_reads_require_active_membership_in_the_requested_project(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $otherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->attachProjectUser($project, $context->user);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $own = DesignModelSet::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'created_by' => $context->user->id, 'updated_by' => $context->user->id, 'title' => 'Свой набор', 'revision' => 1,
        ]);
        $other = DesignModelSet::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $otherProject->id,
            'created_by' => $context->user->id, 'updated_by' => $context->user->id, 'title' => 'Чужой набор', 'revision' => 1,
        ]);
        $base = '/api/v1/admin/design-management/model-sets';
        $this->getJson($base, $context->authHeaders())->assertUnprocessable()->assertJsonValidationErrors('project_id');
        $this->getJson($base.'?project_id='.$project->id, $context->authHeaders())->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->getJson($base.'/'.$own->id, $context->authHeaders())->assertOk();
        $this->getJson($base.'?project_id='.$otherProject->id, $context->authHeaders())->assertForbidden();
        $this->getJson($base.'/'.$other->id, $context->authHeaders())->assertForbidden();
        $project->users()->updateExistingPivot($context->user->id, ['is_active' => false]);
        $this->getJson($base.'?project_id='.$project->id, $context->authHeaders())->assertForbidden();
        $this->getJson($base.'/'.$own->id, $context->authHeaders())->assertForbidden();
    }

    public function test_model_element_links_are_version_scoped_and_require_explicit_replacement(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess(['workflow-management']);
        $package = DesignPackage::query()->findOrFail($this->createPackage($context, $project));
        $old = $this->storedVersion($package, $context->user);
        $old->update(['file_format' => 'ifc']);
        $next = $old->replicate();
        $next->version_number = '2';
        $next->save();
        foreach ([[$old, 10], [$old, 11], [$next, 20]] as [$version, $expressId]) {
            DesignIfcModelElement::query()->create([
                'organization_id' => $context->organization->id, 'project_id' => $project->id,
                'version_id' => $version->id, 'express_id' => $expressId, 'global_id' => 'same-global-id',
                'category' => 'IFCWALL', 'name' => 'Стена '.$expressId, 'properties' => [],
            ]);
        }
        $work = \App\Models\CompletedWork::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'user_id' => $context->user->id, 'quantity' => 3, 'price' => 500, 'total_amount' => 1500,
            'completion_date' => '2026-09-09', 'description' => 'Работа', 'status' => 'confirmed',
        ]);
        $service = app(DesignSourceLinkService::class);
        $payload = ['source_version_id' => $old->id, 'source_element_id' => 10, 'target_type' => 'completed_work', 'target_id' => $work->id];
        $created = $this->postJson('/api/v1/admin/design-management/source-links', $payload, $context->authHeaders())
            ->assertCreated()->assertJsonPath('data.source_element_id', 10)->assertJsonPath('data.revision', 1);
        $link = $created->json('data');
        $this->assertSame($link['id'], $service->create($context->user, $context->organization->id, $payload)['id']);
        $other = $service->create($context->user, $context->organization->id, array_replace($payload, ['source_element_id' => 11]));
        $this->assertNotSame($link['id'], $other['id']);
        $this->assertSame('Стена 10', $link['source']['element']['name']);
        $service->createImpactReviewsForRevision($old, $next);
        $review = \App\BusinessModules\Features\DesignManagement\Models\DesignImpactReview::query()->where('link_id', $link['id'])->firstOrFail();
        $reviewContext = collect($service->reviewsForSource($context->user, $context->organization->id, $next->id))->firstWhere('id', $review->id);
        $this->assertSame($work->id, $reviewContext['target']['id']);
        $this->assertSame('Стена 10', $reviewContext['source']['element']['name']);
        $this->assertSame($link['source']['element']['global_id'], $reviewContext['source']['element']['global_id']);
        foreach ([null, 10] as $invalidElement) {
            try {
                $service->decideReview($context->user, $context->organization->id, $review->id, 'move_to_new', 'Проверено', 1, null, $invalidElement);
                $this->fail('Missing or old-version element accepted');
            } catch (\DomainException $exception) {
                $this->assertSame(trans_message($invalidElement === null ? 'design_links.errors.new_element_required' : 'design_links.errors.source_element_not_found'), $exception->getMessage());
                $this->assertSame('pending', $review->fresh()->status);
            }
        }
        $decisionUrl = '/api/v1/admin/design-management/impact-reviews/'.$review->id.'/decision';
        $decisionPayload = ['decision' => 'move_to_new', 'reason' => 'Проверено', 'new_source_element_id' => 20];
        $this->postJson($decisionUrl, $decisionPayload, $context->authHeaders())->assertUnprocessable()->assertJsonValidationErrors('expected_revision');
        $this->postJson($decisionUrl, $decisionPayload + ['expected_revision' => 99], $context->authHeaders())->assertUnprocessable();
        $this->assertSame('pending', $review->fresh()->status);
        $this->postJson($decisionUrl, $decisionPayload + ['expected_revision' => 1], $context->authHeaders())
            ->assertOk()->assertJsonPath('data.status', 'decided')->assertJsonPath('data.revision', 2);
        $this->postJson($decisionUrl, $decisionPayload + ['expected_revision' => 1], $context->authHeaders())->assertUnprocessable();
        $this->assertDatabaseHas('design_source_links', ['id' => $link['id'], 'source_version_id' => $old->id, 'source_element_id' => 10, 'status' => 'replaced']);
        $this->assertDatabaseHas('design_source_links', ['source_version_id' => $next->id, 'source_element_id' => 20, 'target_id' => $work->id, 'status' => 'active']);
        $this->assertSame('1500.00', $work->fresh()->total_amount);
        $replacementId = DesignSourceLink::query()->findOrFail($link['id'])->replacement_link_id;
        $endUrl = '/api/v1/admin/design-management/source-links/'.$replacementId;
        $this->deleteJson($endUrl, ['expected_revision' => 1], $context->authHeaders())->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->deleteJson($endUrl, ['reason' => 'Отмена связи'], $context->authHeaders())->assertUnprocessable()->assertJsonValidationErrors('expected_revision');
        $this->deleteJson($endUrl, ['reason' => 'Отмена связи', 'expected_revision' => 1], $context->authHeaders())->assertOk();
        $this->assertDatabaseHas('design_source_links', ['id' => $replacementId, 'status' => 'ended', 'row_version' => 2, 'ended_reason' => 'Отмена связи']);
        $this->assertDatabaseHas('design_impact_reviews', ['id' => $review->id, 'status' => 'decided', 'decision' => 'move_to_new']);
    }

    public function test_impact_decisions_preserve_history_and_completed_work_values(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess(['workflow-management']);
        $package = DesignPackage::query()->findOrFail($this->createPackage($context, $project));
        $previous = $this->storedVersion($package, $context->user);
        $next = $previous->artifact->versions()->create(array_merge($previous->only(['organization_id', 'project_id', 'created_by', 'updated_by', 'uploaded_by', 'title', 'source_format', 'file_format', 'source_file_path', 'source_original_name', 'source_mime_type', 'source_size_bytes', 'source_sha256']), ['version_number' => '2', 'status' => 'uploaded', 'metadata' => []]));
        $service = app(DesignSourceLinkService::class);
        $third = $next->replicate();
        $third->version_number = '3';
        $third->save();
        $oldSheet = DesignDocumentSheet::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'package_id' => $package->id, 'artifact_id' => $previous->artifact_id, 'version_id' => $previous->id,
            'sheet_number' => '1', 'sheet_title' => 'Прежний лист',
        ]);
        foreach (range(1, 51) as $number) {
            $newSheet = DesignDocumentSheet::query()->create([
                'organization_id' => $context->organization->id, 'project_id' => $project->id,
                'package_id' => $package->id, 'artifact_id' => $next->artifact_id, 'version_id' => $next->id,
                'sheet_number' => (string) $number, 'sheet_title' => 'Новый лист '.$number,
            ]);
        }
        foreach (['keep_old', 'move_to_new', 'end'] as $decision) {
            $work = \App\Models\CompletedWork::query()->create([
                'organization_id' => $context->organization->id, 'project_id' => $project->id,
                'user_id' => $context->user->id, 'quantity' => 3, 'completed_quantity' => 3,
                'price' => 500, 'total_amount' => 1500, 'completion_date' => '2026-09-09',
                'description' => 'Работа', 'status' => 'confirmed',
            ]);
            $before = $work->fresh()->getAttributes();
            $created = $service->create($context->user, $context->organization->id, [
                'source_version_id' => $previous->id, 'target_type' => 'completed_work', 'target_id' => $work->id,
                'source_sheet_id' => $decision === 'move_to_new' ? $oldSheet->id : null,
            ]);
            $service->createImpactReviewsForRevision($previous, $next);
            $service->createImpactReviewsForRevision($previous, $third);
            $review = \App\BusinessModules\Features\DesignManagement\Models\DesignImpactReview::query()->where('link_id', $created['id'])->firstOrFail();
            try {
                $service->decideReview($context->user, $context->organization->id, $review->id, $decision, 'Устарело', 99);
                $this->fail('Stale revision was accepted');
            } catch (\DomainException $exception) {
                $this->assertSame(trans_message('design_links.errors.stale_revision'), $exception->getMessage());
                $this->assertSame('pending', $review->fresh()->status);
            }
            if ($decision === 'move_to_new') {
                $this->getJson('/api/v1/admin/design-management/impact-reviews/'.$review->id.'/replacement-sheets?page=2', $context->authHeaders())
                    ->assertOk()->assertJsonPath('data.pagination.total', 51)->assertJsonPath('data.data.0.id', $newSheet->id)->assertJsonCount(1, 'data.data');
                foreach ([null, $oldSheet->id] as $invalidSheet) {
                    try {
                        $service->decideReview($context->user, $context->organization->id, $review->id, $decision, 'Проверено', 1, $invalidSheet);
                        $this->fail('Missing or old sheet accepted');
                    } catch (\DomainException $exception) {
                        $this->assertSame(trans_message($invalidSheet === null ? 'design_links.errors.new_sheet_required' : 'design_links.errors.source_sheet_not_found'), $exception->getMessage());
                        $this->assertSame('pending', $review->fresh()->status);
                    }
                }
            }
            $service->decideReview($context->user, $context->organization->id, $review->id, $decision, 'Проверено', 1, $decision === 'move_to_new' ? $newSheet->id : null);
            $link = DesignSourceLink::query()->findOrFail($created['id']);
            $this->assertSame($previous->id, $link->source_version_id);
            $this->assertSame(match ($decision) { 'keep_old' => 'active', 'move_to_new' => 'replaced', default => 'ended' }, $link->status);
            $this->assertSame($before, $work->fresh()->getAttributes());
            $this->assertSame('decided', $review->fresh()->status);
            $this->assertDatabaseHas('design_impact_reviews', [
                'link_id' => $link->id, 'new_version_id' => $third->id,
                'status' => $decision === 'keep_old' ? 'pending' : 'superseded',
            ]);
            if ($decision === 'move_to_new') {
                $this->assertDatabaseHas('design_source_links', ['id' => $link->replacement_link_id, 'source_version_id' => $next->id, 'target_id' => $work->id, 'status' => 'active']);
                $service->createImpactReviewsForRevision($next, $third);
                $service->delete($context->user, $context->organization->id, $link->replacement_link_id, 'Завершение', 1);
                $this->assertDatabaseHas('design_source_links', ['id' => $link->replacement_link_id, 'status' => 'ended', 'row_version' => 2, 'ended_reason' => 'Завершение']);
                $this->assertDatabaseHas('design_impact_reviews', ['link_id' => $link->replacement_link_id, 'status' => 'superseded', 'reason' => 'Завершение']);
            }
            $this->assertSame(2, (int) $link->row_version);
            if ($decision !== 'keep_old') {
                $this->assertSame('Проверено', $link->ended_reason);
                $this->assertEquals($context->user->id, $link->ended_by);
                $this->assertNotNull($link->ended_at);
            }
            try {
                $service->decideReview($context->user, $context->organization->id, $review->id, 'end', 'Повтор', 1);
                $this->fail('A second decision was accepted');
            } catch (\DomainException $exception) {
                $this->assertSame(trans_message('design_links.errors.review_already_decided'), $exception->getMessage());
            }
        }
        $this->assertSame(0, $service->createImpactReviewsForRevision($previous, $next));
    }

    public function test_link_search_and_creation_require_target_read_permission(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowModuleAccess();
        $this->allowAdminAccess([
            'budget-estimates.view', 'schedule.view', 'construction-journal.view',
            'completed_works.view', 'procurement.purchase_requests.view', 'executive-documentation.view',
        ]);
        $package = DesignPackage::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'created_by' => $context->user->id, 'updated_by' => $context->user->id,
            'title' => 'Связи', 'status' => 'draft', 'metadata' => [],
        ]);
        $version = $this->storedVersion($package, $context->user);
        $service = app(DesignSourceLinkService::class);
        foreach (['estimate_item', 'schedule_task', 'construction_journal_entry', 'completed_work', 'purchase_request', 'executive_document'] as $type) {
            foreach (['search', 'create'] as $operation) {
                try {
                    if ($operation === 'search') {
                        $service->searchTargets($context->user, $context->organization->id, $project->id, $type, 'Объект');
                    } else {
                        $service->create($context->user, $context->organization->id, [
                            'source_version_id' => $version->id, 'target_type' => $type, 'target_id' => 999999,
                        ]);
                    }
                    $this->fail("Target access was not checked: {$type}/{$operation}");
                } catch (\DomainException $exception) {
                    $this->assertSame(trans_message('design_links.errors.forbidden'), $exception->getMessage());
                }
            }
        }
        $this->assertDatabaseMissing('design_source_links', ['source_version_id' => $version->id]);
        foreach (['estimate_item', 'schedule_task', 'construction_journal_entry', 'completed_work', 'purchase_request', 'executive_document'] as $type) {
            $link = DesignSourceLink::query()->create([
                'organization_id' => $context->organization->id, 'project_id' => $project->id,
                'source_version_id' => $version->id, 'target_type' => $type, 'target_id' => 999999,
                'target_snapshot' => ['label' => 'Закрытый объект'], 'created_by' => $context->user->id,
            ]);
            \App\BusinessModules\Features\DesignManagement\Models\DesignImpactReview::query()->create([
                'organization_id' => $context->organization->id, 'project_id' => $project->id,
                'link_id' => $link->id, 'previous_version_id' => $version->id, 'new_version_id' => $version->id,
                'status' => 'pending',
            ]);
        }
        $this->assertSame([], $service->linksForSource($context->user, $context->organization->id, $version->id));
        $this->assertSame([], $service->reviewsForSource($context->user, $context->organization->id, $version->id));
    }

    public function test_target_links_recheck_the_current_source_and_its_parents(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $otherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $otherContext = AdminApiTestContext::create(roleSlug: 'project_manager');
        $this->allowAdminAccess();
        $this->allowModuleAccess(['workflow-management']);
        $package = DesignPackage::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'created_by' => $context->user->id, 'updated_by' => $context->user->id,
            'title' => 'Исходный комплект', 'status' => 'draft', 'metadata' => [],
        ]);
        $source = $this->storedVersion($package, $context->user);
        $artifact = $source->artifact;
        $work = \App\Models\CompletedWork::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'user_id' => $context->user->id, 'quantity' => 3, 'completed_quantity' => 3,
            'price' => 500, 'total_amount' => 1500, 'completion_date' => '2026-09-09',
            'description' => 'Связанная работа', 'status' => 'confirmed',
        ]);
        $service = app(DesignSourceLinkService::class);
        $created = $service->create($context->user, $context->organization->id, [
            'source_version_id' => $source->id, 'target_type' => 'completed_work', 'target_id' => $work->id,
        ]);
        foreach ([$source, $artifact, $package] as $record) {
            foreach (['project_id' => $otherProject->id, 'organization_id' => $otherContext->organization->id] as $field => $value) {
                $original = $record->getAttribute($field);
                $record->forceFill([$field => $value])->save();
                $links = $service->linksForTarget($context->user, $context->organization->id, 'completed_work', $work->id);
                $this->assertCount(1, $links);
                $this->assertFalse($links[0]['source']['available']);
                $this->assertNull($links[0]['source_version_id']);
                $this->assertNull($links[0]['source_sheet_id']);
                $this->assertNull($links[0]['source_element_id']);
                $this->assertSame($created['source']['title'], $links[0]['source']['title']);
                try {
                    $service->linksForSource($context->user, $context->organization->id, $source->id);
                    $this->fail('An out-of-scope source was readable');
                } catch (\DomainException $exception) {
                    $this->assertSame(trans_message('design_links.errors.source_not_found'), $exception->getMessage());
                }
                $this->assertDatabaseHas('design_source_links', ['id' => $created['id'], 'row_version' => 1, 'status' => 'active']);
                try {
                    $service->sourceContext($context->user, $context->organization->id, $created['id']);
                    $this->fail('A moved source or parent must not expose a source context');
                } catch (\DomainException $exception) {
                    $this->assertSame(trans_message('design_links.errors.source_not_found'), $exception->getMessage());
                }
                $record->forceFill([$field => $original])->save();
            }
        }
        $restored = $service->linksForTarget($context->user, $context->organization->id, 'completed_work', $work->id);
        $this->assertTrue($restored[0]['source']['available']);
        $this->assertSame($source->id, $restored[0]['source_version_id']);
    }

    public function test_schedule_link_rechecks_the_parent_organization(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $other = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess(['schedule-management']);
        $package = DesignPackage::query()->findOrFail($this->createPackage($context, $project));
        $source = $this->storedVersion($package, $context->user);
        $schedule = \App\Models\ProjectSchedule::query()->create([
            'project_id' => $project->id, 'organization_id' => $context->organization->id,
            'created_by_user_id' => $context->user->id, 'name' => 'График проекта',
            'planned_start_date' => '2026-09-01', 'planned_end_date' => '2026-09-30', 'status' => 'draft',
        ]);
        $task = \App\Models\ScheduleTask::query()->create([
            'schedule_id' => $schedule->id, 'organization_id' => $context->organization->id,
            'created_by_user_id' => $context->user->id, 'name' => 'Монтаж', 'task_type' => 'task',
            'planned_start_date' => '2026-09-01', 'planned_end_date' => '2026-09-02',
            'planned_duration_days' => 2, 'status' => 'not_started', 'priority' => 'normal',
            'constraint_type' => 'none', 'level' => 0, 'sort_order' => 1,
        ]);
        $service = app(DesignSourceLinkService::class);
        $data = ['source_version_id' => $source->id, 'target_type' => 'schedule_task', 'target_id' => $task->id];
        $created = $service->create($context->user, $context->organization->id, $data);
        $this->assertCount(1, $service->linksForTarget($context->user, $context->organization->id, 'schedule_task', $task->id));
        $next = $source->replicate();
        $next->version_number = '2';
        $next->save();
        foreach (['keep_old', 'move_to_new', 'end'] as $decision) {
            $linkedTask = $task->replicate();
            $linkedTask->name = 'Монтаж '.$decision;
            $linkedTask->save();
            $taskBefore = $linkedTask->fresh()->getRawOriginal();
            $scheduleBefore = $schedule->fresh()->getRawOriginal();
            $link = $service->create($context->user, $context->organization->id, [
                'source_version_id' => $source->id, 'target_type' => 'schedule_task', 'target_id' => $linkedTask->id,
            ]);
            $service->createImpactReviewsForRevision($source, $next);
            $review = \App\BusinessModules\Features\DesignManagement\Models\DesignImpactReview::query()->where('link_id', $link['id'])->sole();
            $service->decideReview($context->user, $context->organization->id, $review->id, $decision, 'Проектное решение проверено', 1);
            $stored = DesignSourceLink::query()->findOrFail($link['id']);
            $this->assertSame(match ($decision) { 'keep_old' => 'active', 'move_to_new' => 'replaced', default => 'ended' }, $stored->status);
            $this->assertSame($source->id, $stored->source_version_id);
            $this->assertSame('decided', $review->fresh()->status);
            if ($decision === 'move_to_new') {
                $this->assertDatabaseHas('design_source_links', ['id' => $stored->replacement_link_id, 'source_version_id' => $next->id, 'target_id' => $linkedTask->id, 'status' => 'active']);
            }
            $this->assertSame($taskBefore, $linkedTask->fresh()->getRawOriginal());
            $this->assertSame($scheduleBefore, $schedule->fresh()->getRawOriginal());
        }
        $schedule->update(['organization_id' => $other->organization->id]);

        foreach (['create', 'read'] as $operation) {
            try {
                if ($operation === 'create') {
                    $service->create($context->user, $context->organization->id, $data);
                } else {
                    $service->linksForTarget($context->user, $context->organization->id, 'schedule_task', $task->id);
                }
                $this->fail('A schedule from another organization was accepted');
            } catch (\DomainException $exception) {
                $this->assertSame(trans_message('design_links.errors.target_out_of_scope'), $exception->getMessage());
            }
        }
        $this->assertDatabaseHas('design_source_links', ['id' => $created['id'], 'status' => 'active', 'row_version' => 1]);
    }

    public function test_purchase_links_preserve_documents_and_recheck_parent_scope(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $other = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess(['procurement', 'basic-warehouse']);
        $package = DesignPackage::query()->findOrFail($this->createPackage($context, $project));
        $previous = $this->storedVersion($package, $context->user);
        $next = $previous->replicate();
        $next->version_number = '2';
        $next->save();
        $service = app(DesignSourceLinkService::class);

        foreach (['keep_old', 'move_to_new', 'end'] as $decision) {
            $site = \App\BusinessModules\Features\SiteRequests\Models\SiteRequest::query()->create([
                'organization_id' => $context->organization->id, 'project_id' => $project->id,
                'user_id' => $context->user->id, 'title' => 'Цемент на площадку',
                'status' => \App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum::APPROVED,
                'request_type' => \App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum::MATERIAL_REQUEST,
                'priority' => 'medium', 'material_name' => 'Цемент', 'material_quantity' => 100, 'material_unit' => 'кг',
            ]);
            $purchase = \App\BusinessModules\Features\Procurement\Models\PurchaseRequest::query()->create([
                'organization_id' => $context->organization->id, 'site_request_id' => $site->id,
                'request_number' => 'PIR-'.$decision,
                'status' => \App\BusinessModules\Features\Procurement\Enums\PurchaseRequestStatusEnum::PENDING,
                'budget_currency' => 'RUB',
            ]);
            $facts = $purchase->fresh()->getRawOriginal();
            $siteFacts = $site->fresh()->getRawOriginal();
            $data = ['source_version_id' => $previous->id, 'target_type' => 'purchase_request', 'target_id' => $purchase->id];
            $link = $service->create($context->user, $context->organization->id, $data);
            $service->createImpactReviewsForRevision($previous, $next);
            $review = \App\BusinessModules\Features\DesignManagement\Models\DesignImpactReview::query()->where('link_id', $link['id'])->sole();
            $site->update(['organization_id' => $other->organization->id]);
            try {
                $service->decideReview($context->user, $context->organization->id, $review->id, $decision, 'Проверено', 1);
                $this->fail('A purchase request with a foreign parent was accepted');
            } catch (\DomainException $exception) {
                $this->assertSame(trans_message('design_links.errors.target_out_of_scope'), $exception->getMessage());
            }
            $this->assertSame('pending', $review->fresh()->status);
            $site->forceFill(['organization_id' => $context->organization->id, 'updated_at' => $siteFacts['updated_at']])->save();
            $service->decideReview($context->user, $context->organization->id, $review->id, $decision, 'Проверено', 1);
            $stored = DesignSourceLink::query()->findOrFail($link['id']);
            $this->assertSame(match ($decision) { 'keep_old' => 'active', 'move_to_new' => 'replaced', default => 'ended' }, $stored->status);
            $this->assertSame($previous->id, $stored->source_version_id);
            if ($decision === 'move_to_new') {
                $this->assertDatabaseHas('design_source_links', ['id' => $stored->replacement_link_id, 'source_version_id' => $next->id, 'target_id' => $purchase->id, 'status' => 'active']);
            }
            $this->assertSame($facts, $purchase->fresh()->getRawOriginal());
            $this->assertSame($siteFacts, $site->fresh()->getRawOriginal());
        }
    }

    public function test_journal_entries_without_estimate_or_task_support_source_links(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess(['budget-estimates']);
        $package = DesignPackage::query()->findOrFail($this->createPackage($context, $project));
        $source = $this->storedVersion($package, $context->user);
        $next = $source->replicate();
        $next->version_number = '2';
        $next->save();
        $journal = \App\Models\ConstructionJournal::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'name' => 'Журнал проекта', 'journal_number' => '1', 'start_date' => '2026-09-01', 'status' => 'active',
            'created_by_user_id' => $context->user->id,
        ]);
        $service = app(DesignSourceLinkService::class);
        foreach (['keep_old', 'move_to_new', 'end'] as $index => $decision) {
            $entry = \App\Models\ConstructionJournalEntry::query()->create([
                'journal_id' => $journal->id, 'entry_date' => '2026-09-12', 'entry_number' => 100 + $index,
                'created_by_user_id' => $context->user->id,
                'work_description' => 'Бетонирование '.$decision,
            ]);
            $before = $entry->fresh()->getRawOriginal();
            $found = $service->searchTargets($context->user, $context->organization->id, $project->id, 'construction_journal_entry', (string) $entry->entry_number);
            $this->assertSame([$entry->id], array_column($found, 'id'));
            $link = $service->create($context->user, $context->organization->id, [
                'source_version_id' => $source->id, 'target_type' => 'construction_journal_entry', 'target_id' => $entry->id,
            ]);
            $service->createImpactReviewsForRevision($source, $next);
            $review = \App\BusinessModules\Features\DesignManagement\Models\DesignImpactReview::query()->where('link_id', $link['id'])->sole();
            $service->decideReview($context->user, $context->organization->id, $review->id, $decision, 'Проверено', 1);
            $stored = DesignSourceLink::query()->findOrFail($link['id']);
            $this->assertSame(match ($decision) { 'keep_old' => 'active', 'move_to_new' => 'replaced', default => 'ended' }, $stored->status);
            if ($decision === 'move_to_new') {
                $this->assertDatabaseHas('design_source_links', ['id' => $stored->replacement_link_id, 'source_version_id' => $next->id, 'target_id' => $entry->id, 'status' => 'active']);
            }
            $this->assertSame($before, $entry->fresh()->getRawOriginal());
        }
    }

    public function test_estimate_and_executive_links_do_not_rewrite_construction_data(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $foreignOrganization = \App\Models\Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess(['budget-estimates', 'executive-documentation', 'contract-management', 'report-templates']);
        $package = DesignPackage::query()->findOrFail($this->createPackage($context, $project));
        $source = $this->storedVersion($package, $context->user);
        $next = $source->replicate();
        $next->version_number = '2';
        $next->save();
        $estimate = \App\Models\Estimate::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'number' => 'PIR-EST', 'name' => 'Смета', 'type' => 'local', 'status' => 'draft',
            'estimate_date' => '2026-09-12', 'total_amount' => 3000, 'total_amount_with_vat' => 3000,
        ]);
        $unit = \App\Models\MeasurementUnit::query()->create([
            'organization_id' => $context->organization->id, 'name' => 'Тестовая единица ПИР', 'short_name' => 'pir-unit',
            'type' => 'work', 'is_default' => false, 'is_system' => false,
        ]);
        $set = \App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'created_by' => $context->user->id, 'set_number' => 'PIR-ID', 'title' => 'Исполнительная документация', 'status' => 'draft',
        ]);
        $service = app(DesignSourceLinkService::class);
        foreach (['keep_old', 'move_to_new', 'end'] as $index => $decision) {
            $targets = [
                'estimate_item' => \App\Models\EstimateItem::query()->create([
                    'estimate_id' => $estimate->id, 'position_number' => (string) ($index + 1), 'item_type' => 'work',
                    'name' => 'Работа '.$decision, 'measurement_unit_id' => $unit->id, 'quantity' => 1,
                    'unit_price' => 1000, 'direct_costs' => 1000, 'total_amount' => 1000, 'is_manual' => true,
                ]),
                'executive_document' => \App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument::query()->create([
                    'organization_id' => $context->organization->id, 'project_id' => $project->id,
                    'document_set_id' => $set->id, 'created_by' => $context->user->id,
                    'document_type' => 'incoming_control_document', 'title' => 'Паспорт '.$decision,
                    'status' => 'draft', 'document_date' => '2026-09-12', 'profile_data' => ['document_number' => 'ПС-'.$index],
                ]),
            ];
            foreach ($targets as $type => $target) {
                $before = $target->fresh()->getRawOriginal();
                $parent = $type === 'estimate_item' ? $estimate : $set;
                $parentBefore = $parent->fresh()->getRawOriginal();
                $link = $service->create($context->user, $context->organization->id, [
                    'source_version_id' => $source->id, 'target_type' => $type, 'target_id' => $target->id,
                ]);
                $service->createImpactReviewsForRevision($source, $next);
                $review = \App\BusinessModules\Features\DesignManagement\Models\DesignImpactReview::query()->where('link_id', $link['id'])->sole();
                $parent->update(['organization_id' => $foreignOrganization->id]);
                try {
                    $service->decideReview($context->user, $context->organization->id, $review->id, $decision, 'Проверено', 1);
                    $this->fail('A target with a foreign parent was accepted');
                } catch (\DomainException $exception) {
                    $this->assertSame(trans_message('design_links.errors.target_out_of_scope'), $exception->getMessage());
                }
                $this->assertSame('pending', $review->fresh()->status);
                $parent->forceFill(['organization_id' => $context->organization->id, 'updated_at' => $parentBefore['updated_at']])->save();
                $service->decideReview($context->user, $context->organization->id, $review->id, $decision, 'Проверено', 1);
                $stored = DesignSourceLink::query()->findOrFail($link['id']);
                $this->assertSame(match ($decision) { 'keep_old' => 'active', 'move_to_new' => 'replaced', default => 'ended' }, $stored->status);
                $this->assertSame($source->id, $stored->source_version_id);
                if ($decision === 'move_to_new') {
                    $this->assertDatabaseHas('design_source_links', ['id' => $stored->replacement_link_id, 'source_version_id' => $next->id, 'target_type' => $type, 'target_id' => $target->id, 'status' => 'active']);
                }
                $this->assertSame($before, $target->fresh()->getRawOriginal());
                $this->assertSame($parentBefore, $parent->fresh()->getRawOriginal());
            }
        }
    }

    public function test_links_and_reviews_recheck_target_scope_and_deletion(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $otherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $otherContext = AdminApiTestContext::create(roleSlug: 'project_manager');
        $this->allowAdminAccess();
        $this->allowModuleAccess(['workflow-management']);
        $package = DesignPackage::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'created_by' => $context->user->id, 'updated_by' => $context->user->id,
            'title' => 'Проверка области связей', 'status' => 'draft', 'metadata' => [],
        ]);
        $previous = $this->storedVersion($package, $context->user);
        $next = $previous->replicate();
        $next->version_number = '2';
        $next->save();
        $work = \App\Models\CompletedWork::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'user_id' => $context->user->id, 'quantity' => 3, 'completed_quantity' => 3,
            'price' => 500, 'total_amount' => 1500, 'completion_date' => '2026-09-09',
            'description' => 'Работа исходного проекта', 'status' => 'confirmed',
        ]);
        $service = app(DesignSourceLinkService::class);
        $created = $service->create($context->user, $context->organization->id, [
            'source_version_id' => $previous->id, 'target_type' => 'completed_work', 'target_id' => $work->id,
        ]);
        $service->createImpactReviewsForRevision($previous, $next);
        $review = \App\BusinessModules\Features\DesignManagement\Models\DesignImpactReview::query()->where('link_id', $created['id'])->firstOrFail();
        $this->assertCount(1, $service->linksForSource($context->user, $context->organization->id, $previous->id));
        $this->assertCount(1, $service->reviewsForSource($context->user, $context->organization->id, $next->id));

        foreach ([
            ['project_id' => $otherProject->id],
            ['organization_id' => $otherContext->organization->id],
            ['deleted_at' => now()],
        ] as $change) {
            $work->forceFill($change)->save();
            $this->assertSame([], $service->linksForSource($context->user, $context->organization->id, $previous->id));
            $this->assertSame([], $service->reviewsForSource($context->user, $context->organization->id, $next->id));
            try {
                $service->decideReview($context->user, $context->organization->id, $review->id, 'keep_old', 'Проверено', 1);
                $this->fail('An inaccessible target was accepted');
            } catch (\DomainException $exception) {
                $this->assertContains($exception->getMessage(), [
                    trans_message('design_links.errors.target_out_of_scope'),
                    trans_message('design_links.errors.target_not_found'),
                ]);
            }
            $this->assertSame('pending', $review->fresh()->status);
            $this->assertDatabaseHas('design_source_links', ['id' => $created['id'], 'row_version' => 1, 'status' => 'active']);
            $work->forceFill(['organization_id' => $context->organization->id, 'project_id' => $project->id, 'deleted_at' => null])->save();
        }
        $this->assertCount(1, $service->linksForSource($context->user, $context->organization->id, $previous->id));
        $this->assertCount(1, $service->reviewsForSource($context->user, $context->organization->id, $next->id));
    }

    public function test_links_require_target_modules_and_retain_history_when_pir_is_disabled(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $required = [
            'estimate_item' => ['budget-estimates'],
            'construction_journal_entry' => ['budget-estimates'],
            'schedule_task' => ['schedule-management'],
            'completed_work' => ['workflow-management'],
            'purchase_request' => ['procurement', 'basic-warehouse'],
            'executive_document' => ['executive-documentation', 'project-management', 'contract-management', 'file-management', 'report-templates'],
        ];
        $allModules = array_unique(array_merge(['design-management'], ...array_values($required)));
        $active = $allModules;
        $this->mock(AccessController::class, function (MockInterface $mock) use (&$active): void {
            $mock->shouldReceive('hasModuleAccess')->andReturnUsing(function (int $organizationId, string $slug) use (&$active): bool {
                return in_array($slug, $active, true);
            });
        });
        $package = DesignPackage::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'created_by' => $context->user->id, 'updated_by' => $context->user->id,
            'title' => 'Связи и подписки', 'status' => 'draft', 'metadata' => [],
        ]);
        $version = $this->storedVersion($package, $context->user);
        $service = app(DesignSourceLinkService::class);
        foreach ($required as $type => $modules) {
            foreach ($modules as $missing) {
                $active = array_diff($allModules, [$missing]);
                foreach (['search', 'create'] as $operation) {
                    try {
                        if ($operation === 'search') {
                            $service->searchTargets($context->user, $context->organization->id, $project->id, $type, 'Объект');
                        } else {
                            $service->create($context->user, $context->organization->id, ['source_version_id' => $version->id, 'target_type' => $type, 'target_id' => 999999]);
                        }
                        $this->fail("Inactive module accepted: {$type}/{$missing}/{$operation}");
                    } catch (\DomainException $exception) {
                        $this->assertSame(trans_message('design_links.target_module_inactive'), $exception->getMessage());
                    }
                }
            }
        }
        $active = $allModules;
        $work = \App\Models\CompletedWork::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'user_id' => $context->user->id, 'quantity' => 1, 'completed_quantity' => 1,
            'price' => 500, 'total_amount' => 500, 'completion_date' => '2026-09-09',
            'description' => 'Работа', 'status' => 'confirmed',
        ]);
        $link = $service->create($context->user, $context->organization->id, ['source_version_id' => $version->id, 'target_type' => 'completed_work', 'target_id' => $work->id]);
        $next = $version->replicate();
        $next->version_number = '2';
        $next->save();
        $service->createImpactReviewsForRevision($version, $next);
        $review = \App\BusinessModules\Features\DesignManagement\Models\DesignImpactReview::query()->where('link_id', $link['id'])->firstOrFail();
        $active = array_diff($allModules, ['workflow-management']);
        $this->assertSame([], $service->linksForSource($context->user, $context->organization->id, $version->id));
        $this->assertSame([], $service->reviewsForSource($context->user, $context->organization->id, $next->id));
        foreach (['read', 'decide'] as $operation) {
            try {
                if ($operation === 'read') {
                    $service->linksForTarget($context->user, $context->organization->id, 'completed_work', $work->id);
                } else {
                    $service->decideReview($context->user, $context->organization->id, $review->id, 'keep_old', 'Проверено', 1);
                }
                $this->fail('Disabled target module accepted');
            } catch (\DomainException $exception) {
                $this->assertSame(trans_message('design_links.target_module_inactive'), $exception->getMessage());
            }
        }
        $active = array_diff($allModules, ['design-management']);
        $retained = $service->linksForTarget($context->user, $context->organization->id, 'completed_work', $work->id);
        $this->assertCount(1, $retained);
        $this->assertFalse($retained[0]['source']['available']);
        $this->assertNull($retained[0]['source_version_id']);
        $this->assertSame('pending', $review->fresh()->status);
        $this->assertDatabaseHas('design_source_links', ['id' => $link['id'], 'row_version' => 1]);
    }

    public function test_ifc_elements_paginate_and_search_beyond_the_first_page(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->attachProjectUser($project, $context->user);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $package = DesignPackage::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'created_by' => $context->user->id, 'updated_by' => $context->user->id,
            'title' => 'Модель', 'status' => 'draft', 'metadata' => [],
        ]);
        $version = $this->storedVersion($package, $context->user);
        $version->update(['file_format' => 'ifc']);
        foreach (range(1, 105) as $id) {
            DesignIfcModelElement::query()->create([
                'organization_id' => $context->organization->id, 'project_id' => $project->id,
                'version_id' => $version->id, 'express_id' => $id, 'global_id' => 'IFC-'.$id,
                'name' => $id === 105 ? 'Стена 100%_А' : 'Wall '.$id,
                'category' => $id === 105 ? 'IFCSLAB' : 'IFCWALL', 'properties' => [],
            ]);
        }
        $url = '/api/v1/admin/design-management/model-versions/'.$version->id.'/elements';
        $this->withHeaders($context->authHeaders())->getJson($url.'?per_page=50&page=3')->assertOk()
            ->assertJsonCount(5, 'data.data')->assertJsonPath('data.data.0.express_id', 101)
            ->assertJsonPath('data.pagination.last_page', 3)->assertJsonPath('data.pagination.total', 105);
        foreach (['105', 'ifc-105', 'ifcslab', '100%_А'] as $search) {
            $this->getJson($url.'?'.http_build_query(['search' => $search]))->assertOk()
                ->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.express_id', 105);
        }
        $this->getJson($url.'?page=0')->assertStatus(422);
        $project->users()->detach($context->user->id);
        $this->getJson($url.'?search=105')->assertNotFound();
    }

    public function test_quality_and_pir_actions_share_the_same_revision(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->attachProjectUser($project, $context->user);
        $this->allowAdminAccess();
        $this->mock(AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();
        $issue = \App\BusinessModules\Features\QualityControl\Models\QualityDefect::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'created_by' => $context->user->id, 'kind' => 'project', 'defect_number' => 'PIR-QC-REVISION',
            'title' => 'Общее замечание', 'status' => 'open', 'inspection_required' => false,
        ]);
        $pir = '/api/v1/admin/design-management/issues/'.$issue->id;
        $quality = '/api/v1/admin/quality-control/defects/'.$issue->id;
        $this->withHeaders($context->authHeaders())->getJson($quality)->assertOk()
            ->assertJsonPath('data.kind', 'project')->assertJsonPath('data.revision', 1);
        $this->postJson($quality.'/resolve', ['comment' => 'Исправлено'])->assertStatus(422);
        $this->postJson($quality.'/resolve', ['expected_revision' => 1, 'comment' => 'Исправлено'])
            ->assertOk()->assertJsonPath('data.revision', 2);
        $this->postJson($pir.'/verify', ['expected_revision' => 1, 'accepted' => true])->assertStatus(422);
        $this->postJson($pir.'/verify', ['expected_revision' => 2, 'accepted' => true])
            ->assertOk()->assertJsonPath('data.revision', 3);
        $this->postJson($quality.'/verify', ['expected_revision' => 2, 'accepted' => false])
            ->assertStatus(422)->assertJsonPath('message', trans_message('design_issues.errors.stale_revision'));
        self::assertSame('resolved', $issue->fresh()->status->value);
        self::assertSame(2, $issue->statusHistory()->count());

        $construction = $issue->replicate();
        $construction->fill(['kind' => 'construction', 'defect_number' => 'QC-COMPATIBLE', 'status' => 'open']);
        $construction->save();
        $this->postJson('/api/v1/admin/quality-control/defects/'.$construction->id.'/resolve', ['comment' => 'Исправлено'])
            ->assertOk()->assertJsonPath('data.status', 'ready_for_review');
    }

    public function test_issue_actions_require_the_displayed_revision_and_snapshot_preserves_blocking(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->attachProjectUser($project, $context->user);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $this->mock(FileService::class, function (MockInterface $mock) use ($context): void {
            $mock->shouldReceive('upload')->once()->andReturn('org-'.$context->organization->id.'/design-management/issues/snapshot.png');
            $mock->shouldReceive('temporaryUrl')->andReturn('https://example.test/private-snapshot');
        });
        $issue = \App\BusinessModules\Features\QualityControl\Models\QualityDefect::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'created_by' => $context->user->id, 'kind' => 'project', 'defect_number' => 'PIR-REVISION',
            'title' => 'Проверка ревизии', 'status' => 'open', 'inspection_required' => false,
        ]);
        $url = '/api/v1/admin/design-management/issues/'.$issue->id;
        $this->withHeaders($context->authHeaders())->getJson($url)->assertOk()->assertJsonPath('data.revision', 1);
        $this->postJson($url.'/blocking-flag', ['active' => true])->assertStatus(422);
        $this->postJson($url.'/blocking-flag', ['active' => true, 'expected_revision' => 1])
            ->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.is_blocking', true);
        $this->postJson($url.'/blocking-flag', ['active' => false, 'reason' => 'Проверено', 'expected_revision' => 1])
            ->assertStatus(422)->assertJsonPath('message', trans_message('design_issues.errors.stale_revision'));
        $this->post($url.'/snapshot', ['file' => UploadedFile::fake()->image('snapshot.png'), 'expected_revision' => 1])->assertStatus(422);
        $this->post($url.'/snapshot', ['file' => UploadedFile::fake()->image('snapshot.png'), 'expected_revision' => 2])
            ->assertOk()->assertJsonPath('data.revision', 3)->assertJsonPath('data.is_blocking', true);
        $this->postJson($url.'/resolve', ['expected_revision' => 2])->assertStatus(422);
        $this->postJson($url.'/resolve', ['expected_revision' => 3])->assertOk()->assertJsonPath('data.revision', 4);
        $this->postJson($url.'/verify', ['expected_revision' => 4, 'accepted' => true])
            ->assertOk()->assertJsonPath('data.revision', 5)->assertJsonPath('data.status', 'resolved');
        $this->postJson($url.'/verify', ['expected_revision' => 4, 'accepted' => false])->assertStatus(422);
        self::assertSame('resolved', $issue->fresh()->status->value);
        self::assertSame(3, $issue->statusHistory()->count());
    }

    public function test_design_management_routes_require_expected_permissions(): void
    {
        $this->assertRoutePermission('GET', 'api/v1/admin/design-management/packages', 'design-management.view');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/packages', 'design-management.create');
        $this->assertRoutePermission('GET', 'api/v1/admin/design-management/packages/{packageId}', 'design-management.view');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/packages/{packageId}/workflow', 'design-management.view');
        $this->assertRoutePermission('GET', 'api/v1/admin/design-management/normative-sources', 'design-management.normative_catalog.view');
        $this->assertRoutePermission('GET', 'api/v1/admin/design-management/document-templates', 'design-management.normative_catalog.view');
        $this->assertRoutePermission('GET', 'api/v1/admin/design-management/packages/{packageId}/sections', 'design-management.documents.view');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/packages/{packageId}/sections/generate', 'design-management.documents.manage_structure');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/packages/{packageId}/sections/custom', 'design-management.documents.manage_structure');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/packages/{packageId}/sections/{sectionId}/documents', 'design-management.documents.upload');
        $this->assertRoutePermission('PUT', 'api/v1/admin/design-management/document-versions/{versionId}/sheets', 'design-management.documents.edit');
        $this->assertRoutePermission('GET', 'api/v1/admin/design-management/document-versions/{versionId}/source-file', 'design-management.documents.view');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/packages/{packageId}/completeness-checks', 'design-management.norm_control.run');
        $this->assertRoutePermission('GET', 'api/v1/admin/design-management/packages/{packageId}/review-comments', 'design-management.review');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/packages/{packageId}/review-comments', 'design-management.review');
        $this->assertRoutePermission('PATCH', 'api/v1/admin/design-management/review-comments/{commentId}', 'design-management.review');
        $this->assertRoutePermission('GET', 'api/v1/admin/design-management/packages/{packageId}/issue-register', 'design-management.export');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/packages/{packageId}/models', 'design-management.models.upload');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/packages/{packageId}/models/multipart/start', 'design-management.models.upload');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/model-uploads/{uploadId}/parts/{partNumber}', 'design-management.models.upload');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/model-uploads/{uploadId}/complete', 'design-management.models.upload');
        $this->assertRoutePermission('DELETE', 'api/v1/admin/design-management/model-uploads/{uploadId}', 'design-management.models.upload');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/model-versions/{versionId}/derivatives', 'design-management.models.prepare_viewer');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/model-versions/{versionId}/viewer/preparation', 'design-management.models.prepare_viewer');
        $this->assertRoutePermission('GET', 'api/v1/admin/design-management/model-versions/{versionId}/viewer', 'design-management.models.view');
        $this->assertRoutePermission('GET', 'api/v1/admin/design-management/model-versions/{versionId}/source-file', 'design-management.models.view');
        $this->assertRoutePermission('GET', 'api/v1/admin/design-management/model-versions/{versionId}/derivative-file', 'design-management.models.view');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/model-versions/{versionId}/mark-current', 'design-management.edit');
        $this->assertRoutePermission('GET', 'api/v1/admin/design-management/model-sets', 'design-management.models.view');
        $this->assertRoutePermission('GET', 'api/v1/admin/design-management/model-sets/{setId}', 'design-management.models.view');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/model-sets', 'design-management.models.edit');
        $this->assertRoutePermission('PUT', 'api/v1/admin/design-management/model-sets/{setId}', 'design-management.models.edit');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/model-sessions', 'design-management.models.view');
        $this->assertRoutePermission('GET', 'api/v1/admin/design-management/model-sessions', 'design-management.models.view');
        $this->assertRoutePermission('GET', 'api/v1/admin/design-management/model-sessions/{sessionId}/bootstrap', 'design-management.models.view');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/model-sessions/{sessionId}/events', 'design-management.models.view');
    }

    public function test_model_set_keeps_concrete_versions_and_creates_immutable_revisions(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $this->fakeFileStorage();
        $this->attachProjectUser($project, $context->user);
        $first = $this->uploadModel($context, $project);
        $second = $this->uploadModel($context, $project);

        $created = $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/design-management/model-sets', [
            'project_id' => $project->id,
            'title' => 'Координационная модель',
            'version_ids' => [$first->id],
            'transforms' => [(string) $first->id => ['shift' => [1.5, 0, -2], 'rotation' => 90]],
        ]);

        $created->assertCreated()->assertJsonPath('data.revision', 1)->assertJsonPath('data.revisions.0.version_ids.0', $first->id);
        $setId = (int) $created->json('data.id');

        $updated = $this->withHeaders($context->authHeaders())->putJson("/api/v1/admin/design-management/model-sets/{$setId}", [
            'expected_revision' => 1,
            'title' => 'Координационная модель',
            'version_ids' => [$first->id, $second->id],
            'transforms' => [(string) $second->id => ['shift' => [0, 3, 0], 'rotation' => -15]],
        ]);

        $updated->assertOk()->assertJsonPath('data.revision', 2);
        $set = DesignModelSet::query()->with('revisions')->findOrFail($setId);
        $this->assertCount(2, $set->revisions);
        $this->assertSame([$first->id], $set->revisions->firstWhere('revision', 1)->version_ids);
        $this->assertSame([$first->id, $second->id], $set->revisions->firstWhere('revision', 2)->version_ids);

        $this->withHeaders($context->authHeaders())->putJson("/api/v1/admin/design-management/model-sets/{$setId}", [
            'expected_revision' => 1,
            'version_ids' => [$second->id],
        ])->assertStatus(409)->assertJsonPath('message', trans_message('design_bim.errors.revision_conflict'));
    }

    public function test_session_is_anchored_to_selected_set_revision_and_bootstrap_is_persistent_only(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $this->fakeFileStorage();
        $this->attachProjectUser($project, $context->user);
        $version = $this->uploadModel($context, $project);
        $set = $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/design-management/model-sets', [
            'project_id' => $project->id, 'title' => 'Набор', 'version_ids' => [$version->id],
        ])->assertCreated();

        $session = $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/design-management/model-sessions', [
            'project_id' => $project->id, 'model_set_id' => $set->json('data.id'), 'model_set_revision' => 1, 'title' => 'Совещание',
        ])->assertCreated();

        $sessionId = (int) $session->json('data.id');
        $this->assertSame(1, $session->json('data.model_set_revision'));
        $this->assertSame([$version->id], $session->json('data.models'));
        $this->assertDatabaseHas('design_model_sessions', ['id' => $sessionId, 'model_set_id' => $set->json('data.id')]);
        $this->assertSame(1, DesignModelSession::query()->findOrFail($sessionId)->modelSetRevision->revision);
        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/design-management/model-sessions?project_id={$project->id}&per_page=10")
            ->assertOk()
            ->assertJsonPath('data.0.id', $sessionId)
            ->assertJsonPath('data.0.model_set_revision', 1)
            ->assertJsonPath('meta.total', 1);
        $this->withHeaders($context->authHeaders())->getJson("/api/v1/admin/design-management/model-sessions/{$sessionId}/bootstrap")
            ->assertOk()->assertJsonPath('data.models', [$version->id])->assertJsonMissingPath('data.cursor');
    }

    public function test_transient_relay_requires_membership_and_does_not_accept_spoofed_sender(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $this->fakeFileStorage();
        $this->attachProjectUser($project, $context->user);
        $version = $this->uploadModel($context, $project);
        $set = $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/design-management/model-sets', [
            'project_id' => $project->id,
            'title' => 'Набор',
            'version_ids' => [$version->id],
        ])->assertCreated();
        $session = $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/design-management/model-sessions', [
            'project_id' => $project->id,
            'model_set_id' => $set->json('data.id'),
            'model_set_revision' => 1,
            'title' => 'Совещание',
        ])->assertCreated();
        $url = '/api/v1/admin/design-management/model-sessions/'.$session->json('data.id').'/events';

        $this->withHeaders($context->authHeaders())->postJson($url, [
            'type' => 'cursor',
            'payload' => ['x' => 3, 'y' => 7, 'z' => 0, 'user_id' => 999999],
        ])->assertStatus(422);

        \Illuminate\Support\Facades\Event::fake([DesignModelSessionTransientEvent::class]);
        foreach ([
            ['type' => 'cursor', 'payload' => ['x' => '3', 'y' => 7, 'z' => 0]],
            ['type' => 'camera', 'payload' => ['position' => ['1e999', 0, 0], 'target' => [0, 0, 0]]],
        ] as $invalid) {
            $this->withHeaders($context->authHeaders())->postJson($url, $invalid)->assertUnprocessable();
        }
        \Illuminate\Support\Facades\Event::assertNotDispatched(DesignModelSessionTransientEvent::class);
        $this->withHeaders($context->authHeaders())->postJson($url, [
            'type' => 'camera', 'payload' => ['position' => [1.25, -2, 0], 'target' => [0, 0, 0]],
        ])->assertStatus(202);
        \Illuminate\Support\Facades\Event::assertDispatched(DesignModelSessionTransientEvent::class, function (DesignModelSessionTransientEvent $event): bool {
            $data = $event->broadcastWith();
            return $data['type'] === 'camera' && $data['payload']['position'] === [1.25, -2, 0];
        });
        $this->withHeaders($context->authHeaders())->postJson($url, [
            'type' => 'select',
            'payload' => ['model_version_id' => $version->id, 'element_id' => 'wall-12'],
        ])->assertStatus(202);
        \Illuminate\Support\Facades\Event::assertDispatched(DesignModelSessionTransientEvent::class, function (DesignModelSessionTransientEvent $event) use ($context): bool {
            return $event->broadcastWith()['sender']['id'] === $context->user->id;
        });

        $this->withHeaders($context->authHeaders())->postJson($url, [
            'type' => 'select',
            'payload' => ['model_version_id' => $version->id, 'element_id' => null],
        ])->assertStatus(202);
        \Illuminate\Support\Facades\Event::assertDispatched(DesignModelSessionTransientEvent::class, function (DesignModelSessionTransientEvent $event) use ($version): bool {
            $data = $event->broadcastWith();

            return $data['type'] === 'select' && $data['payload'] === ['model_version_id' => $version->id, 'element_id' => null];
        });
        $this->withHeaders($context->authHeaders())->postJson($url, [
            'type' => 'select',
            'payload' => ['model_version_id' => $version->id + 100000, 'element_id' => null],
        ])->assertUnprocessable();

        $project->users()->detach($context->user->id);
        $this->withHeaders($context->authHeaders())->postJson($url, [
            'type' => 'cursor',
            'payload' => ['x' => 3, 'y' => 7, 'z' => 0],
        ])->assertForbidden();

        $otherContext = AdminApiTestContext::create(roleSlug: 'project_manager');
        $this->withHeaders($otherContext->authHeaders())->postJson($url, [
            'type' => 'cursor',
            'payload' => ['x' => 3, 'y' => 7, 'z' => 0],
        ])->assertForbidden();
    }

    public function test_project_manager_can_create_package(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/design-management/packages', [
                'project_id' => $project->id,
                'title' => 'Раздел АР',
                'stage' => 'rd',
                'discipline' => 'AR',
                'project_stage' => 'rd',
                'composition' => ['brand' => 'AR', 'document_groups' => [['code' => 'AR', 'title' => 'Архитектурные решения']]],
                'planned_issue_date' => now()->addDays(10)->toDateString(),
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.project_id', $project->id);
        $response->assertJsonPath('data.title', 'Раздел АР');
        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonPath('data.status_label', 'Черновик');
        $response->assertJsonPath('data.project_stage', 'rd');
        $response->assertJsonPath('data.normative_profile_code', 'rf_rd_gost_21_101_2026');
        $response->assertJsonPath('data.derivative.status', 'missing');
        $response->assertJsonPath('data.workflow_summary.models_count', 0);
        $response->assertJsonPath('data.workflow_summary.sections_count', 1);
        $this->assertSame([], $response->json('data.problem_flags'));
        $this->assertSame('AR', $response->json('data.sections.0.code'));
    }

    public function test_all_stages_http_creation_persists_exact_preview(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        foreach ([
            'pd' => ['sections' => [
                ['code' => 'AR', 'title' => 'Архитектурные решения'],
                ['code' => 'CUSTOM-PD', 'title' => 'Специальный раздел'],
            ]],
            'rd' => ['brand' => 'CUSTOM-RD', 'document_groups' => [
                ['code' => 'PLANS', 'title' => 'Планы'],
                ['code' => 'DETAILS', 'title' => 'Узлы'],
            ]],
            'survey' => ['items' => [['code' => 'GEO', 'title' => 'Геологический отчёт']]],
            'bim' => ['items' => [['code' => 'FEDERATION', 'title' => 'Сводная модель']]],
        ] as $stage => $composition) {
            $payload = ['project_id' => $project->id, 'project_stage' => $stage, 'composition' => $composition];
            $preview = $this->withHeaders($context->authHeaders())
                ->postJson('/api/v1/admin/design-management/composition/preview', $payload)
                ->assertOk()
                ->json('data.effective_composition');
            $this->assertIsArray($preview);
            $created = $this->withHeaders($context->authHeaders())
                ->postJson('/api/v1/admin/design-management/packages', array_merge($payload, [
                    'title' => 'Предпросмотр '.$stage, 'stage' => $stage, 'composition' => $preview,
                ]))
                ->assertCreated();
            $package = DesignPackage::query()->findOrFail($created->json('data.id'));
            $revision = \App\BusinessModules\Features\DesignManagement\Models\DesignCompositionRevision::query()
                ->where('package_id', $package->id)->sole();
            $this->assertEquals($preview, $revision->composition);
            $this->assertSame('draft', $revision->status);
            $itemsKey = match ($stage) {
                'pd' => 'sections', 'rd' => 'document_groups', default => 'items',
            };
            $expectedCodes = array_column($preview[$itemsKey], 'code');
            $this->assertSame($expectedCodes, $package->sections()->orderBy('sort_order')->pluck('code')->all());
            $this->assertSame($stage, $package->getRawOriginal('project_stage'));
            if ($stage === 'rd') {
                $this->assertSame('CUSTOM-RD', $revision->composition['brand']);
            }
        }
    }

    public function test_package_creation_requires_explicit_composition(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/design-management/packages', ['project_id' => $project->id, 'title' => 'Без состава'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['composition']);
    }

    public function test_impact_reviews_are_idempotent_and_do_not_mutate_work_facts(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $package = DesignPackage::query()->findOrFail($this->createPackage($context, $project));
        $previous = $this->storedVersion($package, $context->user);
        $next = $previous->artifact->versions()->create(array_merge($previous->only(['organization_id', 'project_id', 'created_by', 'updated_by', 'uploaded_by', 'title', 'source_format', 'file_format', 'source_file_path', 'source_original_name', 'source_mime_type', 'source_size_bytes', 'source_sha256']), ['version_number' => '2', 'status' => 'uploaded', 'metadata' => []]));
        $link = DesignSourceLink::query()->create(['organization_id' => $context->organization->id, 'project_id' => $project->id, 'source_version_id' => $previous->id, 'target_type' => 'completed_work', 'target_id' => 999999, 'target_snapshot' => ['type' => 'completed_work', 'label' => 'Работа'], 'created_by' => $context->user->id]);
        $factsBefore = \App\Models\CompletedWork::query()->count();
        $service = app(DesignSourceLinkService::class);

        $this->assertSame(1, $service->createImpactReviewsForRevision($previous, $next));
        $this->assertSame(0, $service->createImpactReviewsForRevision($previous, $next));
        $this->assertDatabaseHas('design_impact_reviews', ['link_id' => $link->id, 'previous_version_id' => $previous->id, 'new_version_id' => $next->id, 'status' => 'pending']);
        $this->assertSame($factsBefore, \App\Models\CompletedWork::query()->count());
    }

    public function test_ended_source_link_can_be_created_again_without_rewriting_history(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess(['workflow-management']);
        $package = DesignPackage::query()->findOrFail($this->createPackage($context, $project));
        $source = $this->storedVersion($package, $context->user);
        $sheet = DesignDocumentSheet::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'package_id' => $package->id, 'artifact_id' => $source->artifact_id, 'version_id' => $source->id,
            'sheet_number' => '1', 'sheet_title' => 'План',
        ]);
        $work = \App\Models\CompletedWork::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'user_id' => $context->user->id, 'quantity' => 3, 'completed_quantity' => 3,
            'price' => 500, 'total_amount' => 1500, 'completion_date' => '2026-09-09',
            'description' => 'Работа', 'status' => 'confirmed',
        ]);
        $facts = $work->fresh()->getRawOriginal();
        $service = app(DesignSourceLinkService::class);

        $otherContext = AdminApiTestContext::create(roleSlug: 'project_manager');
        $otherProject = Project::factory()->create(['organization_id' => $otherContext->organization->id]);
        $otherPackage = DesignPackage::query()->findOrFail($this->createPackage($otherContext, $otherProject));
        $otherSource = $this->storedVersion($otherPackage, $otherContext->user);
        foreach ([
            'organization_id' => $otherContext->organization->id,
            'project_id' => $otherProject->id,
            'package_id' => $otherPackage->id,
            'artifact_id' => $otherSource->artifact_id,
            'version_id' => $otherSource->id,
        ] as $field => $foreignId) {
            $original = $sheet->getAttribute($field);
            $sheet->forceFill([$field => $foreignId])->save();
            try {
                $service->create($context->user, $context->organization->id, [
                    'source_version_id' => $source->id, 'source_sheet_id' => $sheet->id,
                    'target_type' => 'completed_work', 'target_id' => $work->id,
                ]);
                $this->fail('A sheet with a mismatched '.$field.' was accepted');
            } catch (\DomainException $exception) {
                $this->assertSame(trans_message('design_links.errors.source_sheet_not_found'), $exception->getMessage());
            }
            $this->assertSame(0, DesignSourceLink::query()->where('source_version_id', $source->id)->count());
            $sheet->forceFill([$field => $original])->save();
        }

        foreach ([null, $sheet->id] as $sheetId) {
            $data = ['source_version_id' => $source->id, 'source_sheet_id' => $sheetId, 'target_type' => 'completed_work', 'target_id' => $work->id];
            $first = $service->create($context->user, $context->organization->id, $data);
            $service->delete($context->user, $context->organization->id, $first['id'], 'Связь прекращена', 1);
            $ended = DesignSourceLink::query()->findOrFail($first['id'])->getRawOriginal();
            $second = $service->create($context->user, $context->organization->id, $data);
            $retried = $service->create($context->user, $context->organization->id, $data);

            $this->assertNotSame($first['id'], $second['id']);
            $this->assertSame($second['id'], $retried['id']);
            $this->assertSame($ended, DesignSourceLink::query()->findOrFail($first['id'])->getRawOriginal());
            try {
                \Illuminate\Support\Facades\DB::transaction(function () use ($second): void {
                    DesignSourceLink::query()->findOrFail($second['id'])->replicate()->save();
                });
                $this->fail('The database accepted a duplicate active source link');
            } catch (\Illuminate\Database\QueryException $exception) {
                $this->assertSame('23505', $exception->errorInfo[0]);
            }
            $this->assertSame(1, DesignSourceLink::query()->where('source_version_id', $source->id)->where('source_sheet_id', $sheetId)->where('target_id', $work->id)->where('status', 'active')->count());
        }

        $this->assertSame($facts, $work->fresh()->getRawOriginal());
    }

    public function test_issue_creates_pending_review_for_older_linked_version_and_keeps_link(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $package = DesignPackage::query()->findOrFail($this->createPackage($context, $project));
        $this->completeRequiredDocuments($package, $context->user);
        $this->approveComposition($context, $package->id);
        $previous = DesignArtifactVersion::query()
            ->where('artifact_id', DesignArtifact::query()->where('package_id', $package->id)->where('requires_sheet_registry', false)->value('id'))
            ->where('is_current', true)
            ->firstOrFail();

        foreach (['submit_norm_control', 'submit_customer_review', 'approve', 'issue'] as $action) {
            $this->withHeaders($context->authHeaders())->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", ['action' => $action])->assertOk();
        }
        $firstRelease = DesignWorkflowEvent::query()->where('package_id', $package->id)->where('action', 'issue')->sole();
        $this->assertContains($previous->id, $firstRelease->metadata['artifact_version_ids']);
        $firstReleaseSnapshot = $firstRelease->getRawOriginal();
        $this->withHeaders($context->authHeaders())->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
            'action' => 'return_to_work', 'comment' => 'Изменение проектного решения',
        ])->assertOk()->assertJsonPath('data.status', 'returned');

        $current = $previous->artifact->versions()->create(array_merge($previous->only(['organization_id', 'project_id', 'created_by', 'updated_by', 'uploaded_by', 'title', 'source_format', 'file_format', 'source_file_path', 'source_original_name', 'source_mime_type', 'source_size_bytes', 'source_sha256']), ['version_number' => '2', 'status' => 'current', 'is_current' => true, 'metadata' => []]));
        $previous->update(['is_current' => false]);
        $link = DesignSourceLink::query()->create(['organization_id' => $context->organization->id, 'project_id' => $project->id, 'source_version_id' => $previous->id, 'target_type' => 'completed_work', 'target_id' => 999999, 'target_snapshot' => ['type' => 'completed_work', 'label' => 'Работа'], 'created_by' => $context->user->id]);

        $composition = $this->withHeaders($context->authHeaders())->postJson("/api/v1/admin/design-management/composition/packages/{$package->id}/revisions", [
            'composition' => ['brand' => 'AR', 'document_groups' => [['code' => 'AR', 'title' => 'Архитектурные решения']]],
            'expected_revision' => 1,
        ])->assertCreated();
        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/design-management/composition/revisions/'.$composition->json('data.id').'/approve')
            ->assertOk();

        foreach (['submit_norm_control', 'submit_customer_review', 'approve', 'issue'] as $action) {
            $this->withHeaders($context->authHeaders())->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", ['action' => $action])->assertOk();
        }

        $this->assertDatabaseHas('design_source_links', ['id' => $link->id, 'source_version_id' => $previous->id]);
        $this->assertDatabaseHas('design_impact_reviews', ['link_id' => $link->id, 'previous_version_id' => $previous->id, 'new_version_id' => $current->id, 'status' => 'pending']);
        $secondRelease = DesignWorkflowEvent::query()->where('package_id', $package->id)->where('action', 'issue')->orderByDesc('id')->firstOrFail();
        $this->assertNotSame($firstRelease->id, $secondRelease->id);
        $this->assertContains($current->id, $secondRelease->metadata['artifact_version_ids']);
        $this->assertNotContains($previous->id, $secondRelease->metadata['artifact_version_ids']);
        $this->assertSame($firstReleaseSnapshot, $firstRelease->fresh()->getRawOriginal());

        $this->withHeaders($context->authHeaders())->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", ['action' => 'issue'])->assertOk();
        $this->assertSame(1, \App\BusinessModules\Features\DesignManagement\Models\DesignImpactReview::query()->where('link_id', $link->id)->count());
        $this->assertSame(2, DesignWorkflowEvent::query()->where('package_id', $package->id)->where('action', 'issue')->count());
    }

    public function test_project_manager_can_create_custom_section_document(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $packageId = $this->createPackage($context, $project);
        $composition = ['brand' => 'AR', 'document_groups' => [
            ['code' => ' ar ', 'title' => 'АР', 'documents' => [
                ['document_code' => ' ar-01 ', 'document_title' => 'План этажей', 'artifact_type' => 'drawing_set'],
            ]],
        ]];
        $preview = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/design-management/composition/preview', [
                'project_id' => $project->id, 'project_stage' => 'rd', 'composition' => $composition,
            ])->assertOk()->json('data.effective_composition');
        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/composition/packages/{$packageId}/revisions", [
                'composition' => $composition, 'expected_revision' => 1,
            ])->assertCreated();
        $this->assertEquals($preview, $response->json('data.composition'));
        $this->assertSame(['AR'], DesignPackageSection::query()->where('package_id', $packageId)->pluck('code')->all());
        $this->assertDatabaseHas('design_artifacts', [
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'package_id' => $packageId, 'document_code' => 'AR-01', 'document_title' => 'План этажей',
        ]);
    }

    public function test_project_manager_can_append_custom_document_to_existing_custom_section(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $packageId = $this->createPackage($context, $project);
        $document = ['document_code' => 'AR-01', 'document_title' => 'План'];
        $group = ['code' => 'AR', 'documents' => [$document]];
        $url = "/api/v1/admin/design-management/composition/packages/{$packageId}/revisions";
        $this->withHeaders($context->authHeaders())->postJson($url, [
            'composition' => ['brand' => 'AR', 'document_groups' => [$group]], 'expected_revision' => 1,
        ])->assertCreated();
        $artifact = DesignArtifact::query()->where('package_id', $packageId)->where('document_code', 'AR-01')->sole();
        $group['documents'][] = ['document_code' => 'AR-02', 'document_title' => 'Фасады'];
        $this->withHeaders($context->authHeaders())->postJson($url, [
            'composition' => ['brand' => 'AR', 'document_groups' => [$group]], 'expected_revision' => 2,
        ])->assertCreated();
        $this->assertSame($artifact->id, DesignArtifact::query()->where('package_id', $packageId)->where('document_code', 'AR-01')->sole()->id);
        $this->assertSame(['AR-01', 'AR-02'], DesignArtifact::query()->where('package_id', $packageId)->where('section_id', $artifact->section_id)->orderBy('document_code')->pluck('document_code')->all());
    }

    public function test_composition_revision_http_requires_and_checks_the_displayed_revision(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $packageId = $this->createPackage($context, $project);
        $url = "/api/v1/admin/design-management/composition/packages/{$packageId}/revisions";
        $composition = ['brand' => 'AR', 'document_groups' => [['code' => 'AR', 'title' => 'Архитектура']]];
        foreach ([[], ['expected_revision' => null], ['expected_revision' => 0]] as $token) {
            $this->withHeaders($context->authHeaders())->postJson($url, ['composition' => $composition] + $token)->assertUnprocessable();
        }
        $this->withHeaders($context->authHeaders())->postJson($url, ['composition' => $composition, 'expected_revision' => 1])
            ->assertCreated()->assertJsonPath('data.revision_number', 2);
        $this->withHeaders($context->authHeaders())->postJson($url, ['composition' => $composition, 'expected_revision' => 1])->assertUnprocessable();
        $this->assertSame(2, (int) \App\BusinessModules\Features\DesignManagement\Models\DesignCompositionRevision::query()->where('package_id', $packageId)->max('revision_number'));
    }

    public function test_repeated_composition_revision_preserves_document_identity(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $packageId = $this->createPackage($context, $project);
        $composition = ['brand' => 'AR', 'document_groups' => [
            ['code' => 'AR', 'documents' => [['document_code' => 'AR-EXTRA', 'document_title' => 'Дополнение']]],
        ]];
        $url = "/api/v1/admin/design-management/composition/packages/{$packageId}/revisions";
        $this->withHeaders($context->authHeaders())->postJson($url, [
            'composition' => $composition, 'expected_revision' => 1,
        ])->assertCreated();
        $artifact = DesignArtifact::query()->where('package_id', $packageId)->where('document_code', 'AR-EXTRA')->sole();
        $this->withHeaders($context->authHeaders())->postJson($url, [
            'composition' => $composition, 'expected_revision' => 2,
        ])->assertCreated();
        $this->assertSame($artifact->id, DesignArtifact::query()->where('package_id', $packageId)->where('document_code', 'AR-EXTRA')->sole()->id);
        $this->assertSame(['AR'], DesignPackageSection::query()->where('package_id', $packageId)->pluck('code')->all());
    }

    public function test_project_manager_cannot_create_duplicate_custom_document_code_in_same_section(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $packageId = $this->createPackage($context, $project);
        $revisionId = DesignPackage::query()->findOrFail($packageId)->composition_revision_id;
        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/composition/packages/{$packageId}/revisions", [
                'expected_revision' => 1,
                'composition' => ['brand' => 'AR', 'document_groups' => [
                    ['code' => 'AR', 'documents' => [['document_code' => 'AR-01'], ['document_code' => ' ar-01 ']]],
                ]],
            ])->assertStatus(422);
        $this->assertSame($revisionId, DesignPackage::query()->findOrFail($packageId)->composition_revision_id);
        $this->assertDatabaseMissing('design_artifacts', ['package_id' => $packageId, 'document_code' => 'AR-01']);
    }
    public function test_project_manager_can_upload_ifc_model(): void
    {
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $packageId = $this->createPackage($context, $project);

        $response = $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/design-management/packages/{$packageId}/models", [
                'title' => 'Архитектурная модель',
                'discipline' => 'architecture',
                'version_number' => '1',
                'revision' => 'R01',
                'model_date' => now()->toDateString(),
                'file' => UploadedFile::fake()->createWithContent(
                    'building.ifc',
                    "ISO-10303-21;\nHEADER;\nENDSEC;\nEND-ISO-10303-21;"
                ),
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.title', 'Архитектурная модель');
        $response->assertJsonPath('data.source_format', 'ifc');
        $response->assertJsonPath('data.version_number', '1');
        $response->assertJsonPath('data.revision', 'R01');
        $response->assertJsonPath('data.is_current', true);

        $version = DesignArtifactVersion::query()->firstOrFail();
        $this->assertStringStartsWith(
            "org-{$context->organization->id}/pir/projects/{$project->id}/packages/{$packageId}/models/",
            $version->source_file_path
        );
        Storage::disk('s3')->assertExists($version->source_file_path);
    }

    public function test_project_manager_can_start_multipart_ifc_upload(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $packageId = $this->createPackage($context, $project);

        $this->mock(DesignModelMultipartUploader::class, function (MockInterface $mock): void {
            $mock->shouldReceive('start')
                ->once()
                ->withArgs(static fn (DesignPackage $package, int $userId, array $payload): bool => ($payload['file_sha256'] ?? null) === str_repeat('a', 64)
                    && ($payload['last_modified_at'] ?? null) === '2026-09-09T10:00:00+03:00')
                ->andReturn([
                    'upload_id' => 'upload-123',
                    'part_size_bytes' => 5_242_880,
                    'parts_count' => 2,
                    'parts' => [
                        ['part_number' => 1, 'method' => 'POST'],
                        ['part_number' => 2, 'method' => 'POST'],
                    ],
                ]);
        });

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$packageId}/models/multipart/start", [
                'title' => 'Архитектурная модель',
                'version_number' => '1',
                'revision' => 'R01',
                'original_name' => 'building.ifc',
                'file_size_bytes' => 12_000_000,
                'content_type' => 'application/octet-stream',
                'file_sha256' => str_repeat('a', 64),
                'last_modified_at' => '2026-09-09T10:00:00+03:00',
                'make_current' => true,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.upload_id', 'upload-123');
        $response->assertJsonPath('data.part_size_bytes', 5_242_880);
        $response->assertJsonPath('data.parts.0.method', 'POST');
    }

    public function test_project_manager_cannot_read_ifc_element_from_unassigned_project(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $package = DesignPackage::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by' => $context->user->id,
            'updated_by' => $context->user->id,
            'title' => 'Закрытый комплект',
            'status' => 'draft',
            'metadata' => [],
        ]);
        $artifact = $package->artifacts()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by' => $context->user->id,
            'updated_by' => $context->user->id,
            'artifact_type' => 'model',
            'title' => 'Закрытая модель',
            'status' => 'active',
            'metadata' => [],
        ]);
        $version = $artifact->versions()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by' => $context->user->id,
            'updated_by' => $context->user->id,
            'uploaded_by' => $context->user->id,
            'title' => 'Закрытая модель',
            'version_number' => '1',
            'source_format' => 'ifc',
            'file_format' => 'ifc',
            'source_file_path' => 'org-'.$context->organization->id.'/restricted.ifc',
            'source_original_name' => 'restricted.ifc',
            'source_mime_type' => 'application/x-step',
            'source_size_bytes' => 1,
            'status' => 'uploaded',
            'metadata' => [],
        ]);
        DesignIfcModelElement::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'version_id' => $version->id,
            'express_id' => 42,
            'category' => 'IFCWALL',
            'properties' => ['Pset_WallCommon' => ['IsExternal' => true]],
            'classifications' => [],
        ]);

        $response = $this->withHeaders($context->authHeaders())->getJson(
            "/api/v1/admin/design-management/model-versions/{$version->id}/elements/42/properties"
        );

        $response->assertNotFound();
        $response->assertJsonMissing(['IsExternal']);
    }

    public function test_multipart_start_rejects_invalid_file_identity(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $packageId = $this->createPackage($context, $project);
        $this->mock(DesignModelMultipartUploader::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('start');
        });

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$packageId}/models/multipart/start", [
                'title' => 'Архитектурная модель',
                'version_number' => '1',
                'original_name' => 'building.ifc',
                'file_size_bytes' => 1024,
                'file_sha256' => 'not-a-sha256',
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['file_sha256']);
    }

    public function test_project_manager_can_upload_multipart_ifc_part_through_api(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $this->mock(DesignModelMultipartUploader::class, function (MockInterface $mock): void {
            $mock->shouldReceive('uploadPart')
                ->once()
                ->withArgs(static fn (
                    int $organizationId,
                    int $userId,
                    string $uploadId,
                    int $partNumber,
                    UploadedFile $chunk
                ): bool => $uploadId === 'upload-123'
                    && $partNumber === 1
                    && $chunk->getClientOriginalName() === 'building.ifc.part-1')
                ->andReturn([
                    'upload_id' => 'upload-123',
                    'part_number' => 1,
                    'etag' => '"etag-1"',
                    'size_bytes' => 1024,
                ]);
        });

        $response = $this->withHeaders($context->authHeaders())
            ->post('/api/v1/admin/design-management/model-uploads/upload-123/parts/1', [
                'chunk' => UploadedFile::fake()->createWithContent('building.ifc.part-1', str_repeat('A', 1024)),
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.upload_id', 'upload-123');
        $response->assertJsonPath('data.part_number', 1);
        $response->assertJsonPath('data.size_bytes', 1024);
    }

    public function test_project_manager_can_complete_multipart_ifc_upload(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $packageId = $this->createPackage($context, $project);
        $package = DesignPackage::query()->findOrFail($packageId);
        $version = $this->storedVersion($package, $context->user);

        $this->mock(DesignModelMultipartUploader::class, function (MockInterface $mock) use ($version): void {
            $mock->shouldReceive('complete')
                ->once()
                ->withArgs(static fn (int $organizationId, int $userId, string $uploadId): bool => $uploadId === 'upload-123')
                ->andReturn($version);
        });

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/design-management/model-uploads/upload-123/complete');

        $response->assertCreated();
        $response->assertJsonPath('data.id', $version->id);
        $response->assertJsonPath('data.source_original_name', 'building.ifc');
    }

    public function test_ifc_upload_streams_file_to_storage(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $packageId = $this->createPackage($context, $project);
        $disk = Mockery::mock(Filesystem::class);

        $disk->shouldReceive('put')
            ->once()
            ->with(
                Mockery::type('string'),
                Mockery::on(static fn (mixed $contents): bool => is_resource($contents)),
                'private'
            )
            ->andReturn(true);

        $this->app->forgetInstance(DesignManagementService::class);
        $this->mock(FileService::class, function (MockInterface $mock) use ($disk): void {
            $mock->shouldReceive('disk')->andReturn($disk)->byDefault();
        });
        $this->app->forgetInstance(DesignManagementService::class);

        $response = $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/design-management/packages/{$packageId}/models", [
                'title' => 'Архитектурная модель',
                'version_number' => '1',
                'file' => UploadedFile::fake()->createWithContent(
                    'building.ifc',
                    str_repeat("ISO-10303-21;\n", 64)
                ),
            ]);

        $response->assertCreated();
    }

    public function test_viewer_endpoint_returns_source_and_derivative_blocks(): void
    {
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $version = $this->uploadModel($context, $project);
        $this->uploadDerivative($context, $version);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/design-management/model-versions/{$version->id}/viewer");

        $response->assertOk();
        $response->assertJsonPath('data.version.id', $version->id);
        $response->assertJsonPath('data.source.mime_type', 'application/octet-stream');
        $response->assertJsonPath('data.derivative.status', 'ready');
        $response->assertJsonPath('data.derivative.viewer_provider', 'thatopen');
        $response->assertJsonPath('data.derivative.derivative_format', 'thatopen_frag');
        $this->assertStringStartsWith('https://files.example.test/', (string) $response->json('data.source.download_url'));
        $this->assertStringStartsWith('https://files.example.test/', (string) $response->json('data.derivative.download_url'));
        $this->assertArrayNotHasKey('path', $response->json('data.source'));
    }

    public function test_viewer_endpoint_marks_old_converter_derivative_as_missing(): void
    {
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $version = $this->uploadModel($context, $project);
        $path = "org-{$context->organization->id}/pir/projects/{$project->id}/packages/1/models/{$version->id}/viewer/model.frag";
        Storage::disk('s3')->put($path, 'old fragment binary');

        DesignModelDerivative::query()->create([
            'organization_id' => $version->organization_id,
            'project_id' => $version->project_id,
            'version_id' => $version->id,
            'created_by' => $context->user->id,
            'updated_by' => $context->user->id,
            'prepared_by' => $context->user->id,
            'viewer_provider' => 'thatopen',
            'derivative_format' => 'thatopen_frag',
            'derivative_file_path' => $path,
            'status' => 'ready',
            'progress_percent' => 100,
            'processing_stage' => 'ready',
            'metadata' => ['prepared_on' => 'server'],
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/design-management/model-versions/{$version->id}/viewer");

        $response->assertOk();
        $response->assertJsonPath('data.derivative.status', 'missing');
        $response->assertJsonPath('data.derivative.download_url', null);
        $response->assertJsonPath('data.derivative.processing_stage', 'stale');
        $response->assertJsonPath('data.derivative.metadata.is_stale', true);
        $response->assertJsonPath('data.derivative.metadata.required_converter_version', 5);

        $downloadResponse = $this->withHeaders($context->authHeaders())
            ->get("/api/v1/admin/design-management/model-versions/{$version->id}/derivative-file");

        $downloadResponse->assertStatus(422);
    }

    public function test_derivative_upload_accepts_frag_file(): void
    {
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $version = $this->uploadModel($context, $project);

        $response = $this->uploadDerivative($context, $version);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'ready');
        $response->assertJsonPath('data.viewer_provider', 'thatopen');
        $response->assertJsonPath('data.derivative_format', 'thatopen_frag');

        $derivative = DesignModelDerivative::query()->firstOrFail();
        Storage::disk('s3')->assertExists((string) $derivative->derivative_file_path);
    }

    public function test_project_manager_can_move_package_through_rf_documentation_workflow(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $packageId = $this->createPackage($context, $project);
        $package = DesignPackage::query()->findOrFail($packageId);
        $this->completeRequiredDocuments($package, $context->user);
        $this->approveComposition($context, $packageId);

        $submitResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'submit_norm_control',
                'comment' => 'Комплект готов к нормоконтролю',
            ]);

        $submitResponse->assertOk();
        $submitResponse->assertJsonPath('data.status', 'under_norm_control');
        $submitResponse->assertJsonPath('data.workflow_summary.next_action', 'return_to_work');
        $submitResponse->assertJsonPath('data.workflow_summary.available_action_details.0.requires_comment', true);
        $submitResponse->assertJsonPath('data.workflow_events.0.action', 'submit_norm_control');
        $this->assertContains('submit_customer_review', $submitResponse->json('data.available_actions'));

        $returnResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'return_to_work',
                'comment' => 'Нужно уточнить ведомость изменений',
            ]);

        $returnResponse->assertOk();
        $returnResponse->assertJsonPath('data.status', 'returned');
        $returnResponse->assertJsonPath('data.workflow_history.1.action', 'return_to_work');
        $returnResponse->assertJsonPath('data.workflow_history.1.comment', 'Нужно уточнить ведомость изменений');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'submit_norm_control',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'under_norm_control');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'submit_customer_review',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'under_customer_review');

        $approveResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'approve',
            ]);

        $approveResponse->assertOk();
        $approveResponse->assertJsonPath('data.status', 'approved');
        $approveResponse->assertJsonPath('data.workflow_summary.next_action', 'issue');

        $issueResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'issue',
            ]);

        $issueResponse->assertOk();
        $issueResponse->assertJsonPath('data.status', 'issued');
        $issueResponse->assertJsonPath('data.available_actions.0', 'archive');

        $this->assertSame('issued', DesignPackage::query()->findOrFail($package->id)->status->value);
        $this->assertSame(6, DesignWorkflowEvent::query()->where('package_id', $package->id)->count());

        $issued = $package->fresh();
        $releaseEvent = DesignWorkflowEvent::query()->where('package_id', $package->id)->where('action', 'issue')->firstOrFail();
        $releaseSnapshot = $releaseEvent->getRawOriginal();
        $this->assertSame($issued->composition_revision_id, $releaseEvent->metadata['composition_revision_id']);
        $this->assertNotEmpty($releaseEvent->metadata['artifact_version_ids']);
        $this->assertContains('return_to_work', $issueResponse->json('data.available_actions'));

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'return_to_work',
                'comment' => '   ',
            ])->assertStatus(422);
        $this->assertSame('issued', $package->fresh()->status->value);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'return_to_work',
                'comment' => 'Подготовка следующего выпуска',
            ])->assertOk()->assertJsonPath('data.status', 'returned');
        $this->assertEquals($issued->issued_at, $package->fresh()->issued_at);
        $this->assertSame($issued->issued_by, $package->fresh()->issued_by);

        foreach (['submit_norm_control', 'submit_customer_review', 'approve', 'issue'] as $action) {
            $this->withHeaders($context->authHeaders())
                ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                    'action' => $action,
                ])->assertOk();
        }

        $this->assertSame('issued', $package->fresh()->status->value);
        $this->assertSame(2, DesignWorkflowEvent::query()->where('package_id', $package->id)->where('action', 'issue')->count());
        $this->assertSame($releaseSnapshot, $releaseEvent->fresh()->getRawOriginal());
    }

    public function test_return_to_work_requires_comment(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $packageId = $this->createPackage($context, $project);
        $package = DesignPackage::query()->findOrFail($packageId);
        $this->completeRequiredDocuments($package, $context->user);
        $this->approveComposition($context, $packageId);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'submit_norm_control',
            ])
            ->assertOk();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'return_to_work',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', trans_message('design_management.errors.workflow_comment_required'));
        $response->assertJsonValidationErrors(['comment']);
        $this->assertSame('under_norm_control', $package->fresh()->status->value);
    }

    public function test_package_under_norm_control_rejects_model_changes(): void
    {
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $version = $this->uploadModel($context, $project);
        $package = $version->artifact->package;
        $this->completeRequiredDocuments($package, $context->user);
        $this->approveComposition($context, $package->id);
        $versionsBefore = DesignArtifactVersion::query()->count();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'submit_norm_control',
            ])
            ->assertOk();

        $response = $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/design-management/packages/{$package->id}/models", [
                'title' => 'Обновленная архитектурная модель',
                'version_number' => '2',
                'file' => UploadedFile::fake()->createWithContent(
                    'building-v2.ifc',
                    "ISO-10303-21;\nHEADER;\nENDSEC;\nEND-ISO-10303-21;"
                ),
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', trans_message('design_management.errors.package_locked_for_model_changes'));
        $this->assertSame($versionsBefore, DesignArtifactVersion::query()->count());
    }

    public function test_package_cannot_enter_norm_control_without_required_documents(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $packageId = $this->createPackage($context, $project);
        $package = DesignPackage::query()->findOrFail($packageId);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'submit_norm_control',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', trans_message('design_management.errors.completeness_blocked'));
        $this->assertSame('draft', $package->fresh()->status->value);
    }

    public function test_package_workflow_actions_are_idempotent_for_retried_requests(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $packageId = $this->createPackage($context, $project);
        $package = DesignPackage::query()->findOrFail($packageId);
        $this->completeRequiredDocuments($package, $context->user);
        $this->approveComposition($context, $packageId);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'submit_norm_control',
                'comment' => 'ready for norm control',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'under_norm_control');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'submit_norm_control',
                'comment' => 'ready for norm control',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'under_norm_control');

        $history = $package->fresh()->metadata['workflow_history'] ?? [];

        $this->assertCount(1, $history);
        $this->assertSame('submit_norm_control', $history[0]['action']);
        $this->assertSame('draft', $history[0]['from_status']);
        $this->assertSame('under_norm_control', $history[0]['to_status']);
        $this->assertSame(1, DesignWorkflowEvent::query()->where('package_id', $package->id)->count());
    }

    public function test_package_approval_requires_approve_permission(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess(['design-management.approve']);
        $this->allowModuleAccess();
        $packageId = $this->createPackage($context, $project);
        $package = DesignPackage::query()->findOrFail($packageId);
        $this->completeRequiredDocuments($package, $context->user);
        $this->approveComposition($context, $packageId);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'submit_norm_control',
            ])
            ->assertOk();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'submit_customer_review',
            ])
            ->assertOk();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'approve',
            ]);

        $response->assertForbidden();
        $response->assertJsonPath('message', trans_message('design_management.errors.workflow_action_forbidden'));
        $this->assertSame('under_customer_review', $package->fresh()->status->value);
    }

    public function test_review_comment_rejects_target_from_another_package(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $packageId = $this->createPackage($context, $project);
        $otherPackageId = $this->createPackage($context, $project, ['title' => 'Другой комплект']);
        $foreignSection = DesignPackageSection::query()
            ->where('package_id', $otherPackageId)
            ->firstOrFail();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$packageId}/review-comments", [
                'section_id' => $foreignSection->id,
                'severity' => 'blocking',
                'body' => 'Проверить раздел другого комплекта',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', trans_message('design_management.errors.review_target_not_found'));
        $this->assertSame(0, DesignReviewComment::query()->where('package_id', $packageId)->count());
    }

    public function test_prepare_viewer_endpoint_queues_server_side_derivative_processing(): void
    {
        Queue::fake();
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $version = $this->uploadModel($context, $project);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/model-versions/{$version->id}/viewer/preparation");

        $response->assertStatus(202);
        $response->assertJsonPath('data.version_id', $version->id);
        $response->assertJsonPath('data.status', 'queued');
        $response->assertJsonPath('data.progress_percent', 0);
        $response->assertJsonPath('data.processing_stage', 'queued');

        $derivative = DesignModelDerivative::query()->firstOrFail();
        $this->assertSame('queued', $derivative->status->value);
        $this->assertSame(0, $derivative->progress_percent);
        $this->assertSame('queued', $derivative->processing_stage);

        Queue::assertPushedOn('ifc-processing', PrepareDesignModelViewerJob::class);
    }

    public function test_prepare_viewer_endpoint_is_idempotent_for_running_processing(): void
    {
        Queue::fake();
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $version = $this->uploadModel($context, $project);

        DesignModelDerivative::query()->create([
            'organization_id' => $version->organization_id,
            'project_id' => $version->project_id,
            'version_id' => $version->id,
            'created_by' => $context->user->id,
            'updated_by' => $context->user->id,
            'prepared_by' => $context->user->id,
            'viewer_provider' => 'thatopen',
            'derivative_format' => 'thatopen_frag',
            'status' => 'processing',
            'progress_percent' => 35,
            'processing_stage' => 'converting',
            'metadata' => ['prepared_on' => 'server'],
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/model-versions/{$version->id}/viewer/preparation");

        $response->assertStatus(202);
        $response->assertJsonPath('data.status', 'processing');
        $response->assertJsonPath('data.progress_percent', 35);
        $response->assertJsonPath('data.processing_stage', 'converting');

        Queue::assertNothingPushed();
    }

    public function test_prepare_viewer_endpoint_requeues_old_ready_derivative(): void
    {
        Queue::fake();
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $version = $this->uploadModel($context, $project);

        $derivative = DesignModelDerivative::query()->create([
            'organization_id' => $version->organization_id,
            'project_id' => $version->project_id,
            'version_id' => $version->id,
            'created_by' => $context->user->id,
            'updated_by' => $context->user->id,
            'prepared_by' => $context->user->id,
            'viewer_provider' => 'thatopen',
            'derivative_format' => 'thatopen_frag',
            'derivative_file_path' => 'org-1/pir/old/model.frag',
            'status' => 'ready',
            'progress_percent' => 100,
            'processing_stage' => 'ready',
            'metadata' => ['prepared_on' => 'server'],
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/model-versions/{$version->id}/viewer/preparation");

        $response->assertStatus(202);
        $response->assertJsonPath('data.status', 'queued');
        $response->assertJsonPath('data.progress_percent', 0);
        $response->assertJsonPath('data.metadata.converter_version', 5);

        $derivative->refresh();
        $this->assertSame('queued', $derivative->status->value);
        $this->assertNull($derivative->derivative_file_path);

        Queue::assertPushedOn('ifc-processing', PrepareDesignModelViewerJob::class);
    }

    public function test_source_file_endpoint_streams_uploaded_ifc_through_api(): void
    {
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $version = $this->uploadModel($context, $project);

        $response = $this->withHeaders($context->authHeaders())
            ->get("/api/v1/admin/design-management/model-versions/{$version->id}/source-file");

        $response->assertOk();
        $response->assertDownload('building.ifc');
        $this->assertSame(
            "ISO-10303-21;\nHEADER;\nENDSEC;\nEND-ISO-10303-21;",
            $response->streamedContent()
        );
    }

    public function test_derivative_file_endpoint_streams_ready_frag_through_api(): void
    {
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $version = $this->uploadModel($context, $project);
        $this->uploadDerivative($context, $version)->assertCreated();

        $response = $this->withHeaders($context->authHeaders())
            ->get("/api/v1/admin/design-management/model-versions/{$version->id}/derivative-file");

        $response->assertOk();
        $this->assertSame('fragment binary', $response->streamedContent());
    }

    public function test_derivative_upload_rejects_non_frag_file(): void
    {
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $version = $this->uploadModel($context, $project);

        $response = $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/design-management/model-versions/{$version->id}/derivatives", [
                'file' => UploadedFile::fake()->createWithContent('model.txt', 'not a viewer file'),
                'viewer_provider' => 'thatopen',
                'derivative_format' => 'thatopen_frag',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['file']);
    }

    public function test_user_from_another_organization_cannot_access_package(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $foreignContext = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $packageId = $this->createPackage($context, $project);

        $response = $this->withHeaders($foreignContext->authHeaders())
            ->getJson("/api/v1/admin/design-management/packages/{$packageId}");

        $response->assertNotFound();
        $response->assertJsonPath('message', trans_message('design_management.errors.package_not_found'));
        $response->assertJsonMissing(['id' => $packageId, 'title' => 'Раздел АР']);
    }

    public function test_project_viewer_cannot_upload_model(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_viewer');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $package = DesignPackage::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by' => $context->user->id,
            'updated_by' => $context->user->id,
            'title' => 'Раздел КЖ',
            'status' => 'draft',
            'metadata' => [],
        ]);
        $this->allowAdminAccess(['design-management.models.upload'], ['project_viewer']);
        $this->allowModuleAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/design-management/packages/{$package->id}/models", [
                'title' => 'Конструктивная модель',
                'version_number' => '1',
                'file' => UploadedFile::fake()->createWithContent('building.ifc', 'IFC'),
            ]);

        $response->assertForbidden();
    }

    private function createPackage(AdminApiTestContext $context, Project $project, array $overrides = []): int
    {
        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/design-management/packages', array_merge([
                'project_id' => $project->id,
                'title' => 'Раздел АР',
                'stage' => 'rd',
                'discipline' => 'AR',
                'project_stage' => 'rd',
                'composition' => [
                    'brand' => 'AR',
                    'document_groups' => [['code' => 'AR', 'title' => 'Архитектурные решения']],
                ],
            ], $overrides));

        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    private function approveComposition(AdminApiTestContext $context, int $packageId): void
    {
        $revisionId = (int) DesignPackage::query()->findOrFail($packageId)->composition_revision_id;
        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/composition/revisions/{$revisionId}/approve")
            ->assertOk();
    }

    private function uploadModel(AdminApiTestContext $context, Project $project): DesignArtifactVersion
    {
        $packageId = $this->createPackage($context, $project);

        $response = $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/design-management/packages/{$packageId}/models", [
                'title' => 'Архитектурная модель',
                'version_number' => '1',
                'revision' => 'R01',
                'file' => UploadedFile::fake()->createWithContent(
                    'building.ifc',
                    "ISO-10303-21;\nHEADER;\nENDSEC;\nEND-ISO-10303-21;"
                ),
            ]);

        $response->assertCreated();

        return DesignArtifactVersion::query()->latest('id')->firstOrFail();
    }

    private function attachProjectUser(Project $project, User $user): void
    {
        $project->users()->attach($user->id, [
            'role' => 'project_manager',
            'is_active' => true,
            'assigned_at' => now(),
            'assigned_by_user_id' => $user->id,
        ]);
    }

    private function uploadDerivative(AdminApiTestContext $context, DesignArtifactVersion $version): TestResponse
    {
        return $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/design-management/model-versions/{$version->id}/derivatives", [
                'file' => UploadedFile::fake()->createWithContent('model.frag', 'fragment binary'),
                'viewer_provider' => 'thatopen',
                'derivative_format' => 'thatopen_frag',
                'metadata' => [
                    'converted_in_browser' => true,
                ],
            ]);
    }

    private function customSectionDocumentPayload(array $overrides = []): array
    {
        return array_merge([
            'section_code' => 'X_CUSTOM',
            'section_title' => 'Пользовательский раздел',
            'document_code' => 'X_DOC_01',
            'document_title' => 'План этажей',
            'artifact_type' => 'drawing_set',
            'required' => true,
            'allowed_formats' => ['pdf', 'dwg'],
            'sheet_registry_required' => true,
            'normative_reference' => 'СТО 1.001',
        ], $overrides);
    }

    private function storedVersion(DesignPackage $package, User $user): DesignArtifactVersion
    {
        $artifact = $package->artifacts()->create([
            'organization_id' => $package->organization_id,
            'project_id' => $package->project_id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'artifact_type' => 'model',
            'title' => 'Архитектурная модель',
            'status' => 'active',
            'metadata' => [],
        ]);

        return $artifact->versions()->create([
            'organization_id' => $package->organization_id,
            'project_id' => $package->project_id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'uploaded_by' => $user->id,
            'title' => 'Архитектурная модель',
            'version_number' => '1',
            'source_format' => 'ifc',
            'source_file_path' => 'org-'.$package->organization_id.'/pir/model-uploads/upload-123/building.ifc',
            'source_original_name' => 'building.ifc',
            'source_mime_type' => 'application/octet-stream',
            'source_size_bytes' => 12_000_000,
            'status' => 'uploaded',
            'is_current' => true,
            'metadata' => [],
        ]);
    }

    private function completeRequiredDocuments(DesignPackage $package, User $user): void
    {
        $package->loadMissing('sections');

        foreach ($package->sections as $section) {
            if (! $section instanceof DesignPackageSection) {
                continue;
            }

            $documents = is_array($section->metadata['documents'] ?? null) ? $section->metadata['documents'] : [];
            if ($documents === [] && $section->required) {
                $documents = [[
                    'document_code' => $section->code.'-01',
                    'document_title' => 'Документ '.$section->code,
                    'artifact_type' => 'text_document',
                    'required' => true,
                    'allowed_formats' => ['pdf'],
                    'sheet_registry_required' => false,
                ]];
            }

            foreach ($documents as $document) {
                if (! ($document['required'] ?? false)) {
                    continue;
                }

                $format = (string) (($document['allowed_formats'][0] ?? null) ?: 'pdf');
                $documentCode = (string) $document['document_code'];
                $artifact = DesignArtifact::query()->create([
                    'organization_id' => $package->organization_id,
                    'project_id' => $package->project_id,
                    'package_id' => $package->id,
                    'section_id' => $section->id,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                    'artifact_type' => (string) $document['artifact_type'],
                    'document_code' => $documentCode,
                    'document_title' => (string) $document['document_title'],
                    'requires_sheet_registry' => (bool) ($document['sheet_registry_required'] ?? false),
                    'title' => (string) $document['document_title'],
                    'discipline' => $section->code,
                    'stage' => $section->project_stage instanceof \BackedEnum ? $section->project_stage->value : $section->project_stage,
                    'status' => 'active',
                    'metadata' => [],
                ]);
                $version = $artifact->versions()->create([
                    'organization_id' => $package->organization_id,
                    'project_id' => $package->project_id,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                    'uploaded_by' => $user->id,
                    'title' => (string) $document['document_title'],
                    'version_number' => '1',
                    'revision' => 'R01',
                    'revision_label' => 'R01',
                    'source_format' => $format,
                    'file_format' => $format,
                    'source_file_path' => sprintf('org-%d/pir/projects/%d/packages/%d/test/%s.%s', $package->organization_id, $package->project_id, $package->id, strtolower($documentCode), $format),
                    'source_original_name' => strtolower($documentCode).'.'.$format,
                    'source_mime_type' => 'application/octet-stream',
                    'source_size_bytes' => 1024,
                    'source_sha256' => str_repeat('a', 64),
                    'page_count' => (bool) ($document['sheet_registry_required'] ?? false) ? 1 : null,
                    'sheet_count' => (bool) ($document['sheet_registry_required'] ?? false) ? 1 : null,
                    'extracted_metadata' => [],
                    'status' => 'current',
                    'is_current' => true,
                    'metadata' => [],
                ]);

                if ((bool) ($document['sheet_registry_required'] ?? false)) {
                    DesignDocumentSheet::query()->create([
                        'organization_id' => $package->organization_id,
                        'project_id' => $package->project_id,
                        'package_id' => $package->id,
                        'section_id' => $section->id,
                        'artifact_id' => $artifact->id,
                        'version_id' => $version->id,
                        'sheet_number' => '1',
                        'sheet_code' => $documentCode.'-1',
                        'sheet_title' => (string) $document['document_title'],
                        'revision' => 'R01',
                        'file_page_number' => 1,
                        'total_sheets' => 1,
                        'status' => 'active',
                        'metadata' => [],
                    ]);
                }
            }
        }
    }

    private function fakeFileStorage(): void
    {
        Storage::fake('s3');
        $disk = Storage::disk('s3');

        $this->app->forgetInstance(DesignManagementService::class);
        $this->mock(FileService::class, function (MockInterface $mock) use ($disk): void {
            $mock->shouldReceive('disk')->andReturn($disk)->byDefault();
            $mock->shouldReceive('temporaryUrl')->andReturnUsing(
                static fn (?string $path, int $minutes = 5, mixed $organization = null): ?string => $path
                    ? 'https://files.example.test/'.ltrim($path, '/')
                    : null
            )->byDefault();
        });
        $this->app->forgetInstance(DesignManagementService::class);
    }

    private function allowModuleAccess(array $additionalModules = []): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock) use ($additionalModules): void {
            $mock->shouldReceive('hasModuleAccess')->andReturnUsing(
                static fn (int $organizationId, string $moduleSlug): bool => in_array($moduleSlug, [
                    ...$additionalModules,
                    'design-management',
                    'project-management',
                    'file-management',
                ], true)
            );
        });
    }

    private function allowAdminAccess(array $deniedPermissions = [], array $roleSlugs = ['project_manager']): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock) use ($deniedPermissions, $roleSlugs): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturnUsing(
                static fn (User $user, string $permission, ?array $context = null): bool => ! in_array($permission, $deniedPermissions, true)
            );
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn($roleSlugs);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
        });
    }

    private function assertRoutePermission(string $method, string $uri, string $permission): void
    {
        $route = $this->findRoute($method, $uri);

        $this->assertNotNull($route, "Маршрут {$method} {$uri} не найден.");
        $this->assertContains("authorize:{$permission}", $route->gatherMiddleware(), "{$method} {$uri}");
    }

    private function findRoute(string $method, string $uri): ?LaravelRoute
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                return $route;
            }
        }

        return null;
    }
}
