<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Addons\FileManagement\FileManagementModule;
use App\BusinessModules\Features\ContractManagement\ContractManagementModule;
use App\BusinessModules\Features\DesignManagement\DesignManagementModule;
use App\BusinessModules\Features\ExecutiveDocumentation\ExecutiveDocumentationModule;
use App\BusinessModules\Features\ProjectManagement\ProjectManagementModule;
use App\BusinessModules\Services\ReportTemplates\ReportTemplatesModule;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignIfcModelElement;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Events\DesignModelSessionTransientEvent;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\Enums\UserProjectAccessMode;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Module;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\Project;
use App\Modules\Core\AccessController;
use App\Services\Storage\FileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class MobileDesignManagementHttpContractTest extends TestCase
{
    public function test_native_routes_use_mobile_guard_and_broadcasting_auth(): void
    {
        $route = Route::getRoutes()->getByName('api.v1.mobile.design-management.versions.index');
        self::assertNotNull($route);
        foreach (['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app'] as $middleware) {
            self::assertContains($middleware, $route->gatherMiddleware());
        }
        $this->getJson('/api/v1/mobile/design-management/project-model-versions?project_id=1')->assertUnauthorized();
        $auth = collect(Route::getRoutes()->getRoutes())->first(fn ($item): bool => $item->uri() === 'api/v1/mobile/broadcasting/auth');
        self::assertNotNull($auth);
        self::assertContains('auth:api_mobile', $auth->gatherMiddleware());
        $upload = collect(Route::getRoutes()->getRoutes())->filter(fn ($item): bool => str_starts_with($item->uri(), 'api/v1/mobile/design-management') && str_contains($item->uri(), 'upload'));
        self::assertCount(0, $upload);
    }

    public function test_catalog_includes_all_versions_and_paginated_preparation_status(): void
    {
        [$context, $project, $version] = $this->fixture();
        $old = $version->replicate();
        $old->version_number = '0'; $old->is_current = false; $old->status = 'superseded'; $old->save();
        DesignModelDerivative::query()->create(['organization_id' => $context->organization->id, 'project_id' => $project->id,
            'version_id' => $version->id, 'created_by' => $context->user->id, 'viewer_provider' => 'thatopen',
            'derivative_format' => 'thatopen_frag', 'status' => 'queued', 'progress_percent' => 4, 'metadata' => []]);
        DesignModelDerivative::query()->create(['organization_id' => $context->organization->id, 'project_id' => $project->id,
            'version_id' => $old->id, 'viewer_provider' => 'thatopen', 'derivative_format' => 'thatopen_frag',
            'status' => 'ready', 'metadata' => ['converter_version' => 1]]);
        $this->withHeaders($context->mobileAuthHeaders())->getJson($this->base().'/project-model-versions?project_id='.$project->id.'&per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.per_page', 1);
        $this->withHeaders($context->mobileAuthHeaders())->getJson($this->base().'/project-model-versions?project_id='.$project->id.'&status=queued')
            ->assertOk()->assertJsonPath('data.0.id', $version->id)->assertJsonPath('data.0.derivative_status', 'queued')
            ->assertJsonPath('data.0.available_actions.0.key', 'prepare_viewer');
        $this->withHeaders($context->mobileAuthHeaders())->getJson($this->base().'/project-model-versions?project_id='.$project->id.'&status=missing')
            ->assertOk()->assertJsonPath('data.0.id', $old->id)->assertJsonPath('data.0.derivative.processing_stage', 'stale');
    }

    public function test_catalog_keeps_current_converter_status_with_large_private_metadata(): void
    {
        [$context, $project, $version] = $this->fixture();
        $derivative = DesignModelDerivative::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'version_id' => $version->id, 'viewer_provider' => 'thatopen', 'derivative_format' => 'thatopen_frag',
            'status' => 'ready', 'progress_percent' => 100, 'processing_stage' => 'ready',
            'metadata' => ['converter_version' => config('design_management.viewer_converter_version'),
                'coordinate_transformations' => array_fill(0, 2000, ['private' => str_repeat('x', 128)])],
        ]);
        $this->withHeaders($context->mobileAuthHeaders())
            ->getJson($this->base().'/project-model-versions?project_id='.$project->id.'&status=ready')
            ->assertOk()->assertJsonPath('data.0.id', $version->id)
            ->assertJsonPath('data.0.artifact_id', $version->artifact_id)
            ->assertJsonPath('data.0.derivative.id', $derivative->id)
            ->assertJsonPath('data.0.derivative_status', 'ready')
            ->assertJsonPath('data.0.derivative.is_current', true)
            ->assertJsonMissing(['coordinate_transformations']);
    }

    public function test_issue_creation_replay_checks_project_and_exact_version_element(): void
    {
        [$context, $project, $version] = $this->fixture();
        $payload = ['project_id' => $project->id, 'title' => 'Уточнить узел', 'severity' => 'major',
            'model_version_id' => $version->id, 'express_id' => 71, 'view_state' => ['position' => [1, 2, 3], 'sections' => [['normal' => [1, 0, 0], 'constant' => 2]]]];
        $headers = $context->mobileAuthHeaders() + ['Idempotency-Key' => 'mobile_bim_issue_0001'];
        $first = $this->withHeaders($headers)->postJson($this->base().'/project-issues', $payload);
        $first->assertCreated()->assertJsonPath('data.receipt.replayed', false)->assertJsonPath('data.context.bim_element_id', '71');
        $id = $first->json('data.id');
        $this->withHeaders($headers)->postJson($this->base().'/project-issues', $payload)
            ->assertCreated()->assertJsonPath('data.id', $id)->assertJsonPath('data.receipt.replayed', true);
        self::assertSame(1, QualityDefect::query()->where('project_id', $project->id)->count());
        $this->withHeaders($context->mobileAuthHeaders() + ['Idempotency-Key' => 'mobile_bim_issue_0002'])
            ->postJson($this->base().'/project-issues', array_replace($payload, ['express_id' => 72]))->assertUnprocessable();
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => UserProjectAccessMode::ASSIGNED_PROJECTS->value]);
        DB::table('project_user')->where('project_id', $project->id)->where('user_id', $context->user->id)->update(['is_active' => false]);
        $this->withHeaders($headers)->postJson($this->base().'/project-issues', $payload)->assertNotFound();
    }

    public function test_photo_and_snapshot_replays_do_not_duplicate_and_stale_revision_is_conflict(): void
    {
        [$context, $project, $version] = $this->fixture();
        $disk = Storage::fake('s3');
        $files = $this->mock(FileService::class);
        $files->shouldReceive('disk')->andReturn($disk);
        $files->shouldReceive('temporaryUrl')->andReturn('https://files.example.test/mobile-image');
        $files->shouldReceive('describeCurrent')->andReturnUsing(fn (string $path): array => ['path' => $path, 'body' => '',
            'size' => strlen($disk->get($path)), 'sha256' => hash('sha256', $disk->get($path)), 'etag' => 'test-etag', 'content_type' => 'image/png']);
        $create = $this->withHeaders($context->mobileAuthHeaders())->postJson($this->base().'/project-issues',
            ['project_id' => $project->id, 'title' => 'Фото узла', 'severity' => 'major', 'version_id' => $version->id])->assertCreated();
        $id = $create->json('data.id');
        $photo = $this->image();
        $headers = $context->mobileAuthHeaders() + ['Idempotency-Key' => 'mobile_bim_photo_0001'];
        $this->withHeaders($headers)->post($this->base().'/project-issues/'.$id.'/photos', ['file' => $photo, 'expected_revision' => 1])
            ->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.receipt.replayed', false);
        $this->withHeaders($headers)->post($this->base().'/project-issues/'.$id.'/photos', ['file' => $this->image(), 'expected_revision' => 1])
            ->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.receipt.replayed', true);
        self::assertSame(1, DB::table('quality_defect_photos')->where('quality_defect_id', $id)->count());
        $snapshotHeaders = $context->mobileAuthHeaders() + ['Idempotency-Key' => 'mobile_bim_snapshot_0001'];
        $this->withHeaders($snapshotHeaders)->post($this->base().'/project-issues/'.$id.'/snapshot', ['file' => $this->image(), 'expected_revision' => 2])
            ->assertOk()->assertJsonPath('data.revision', 3)->assertJsonPath('data.snapshot_url', 'https://files.example.test/mobile-image');
        $this->withHeaders($snapshotHeaders)->post($this->base().'/project-issues/'.$id.'/snapshot', ['file' => $this->image(), 'expected_revision' => 2])
            ->assertOk()->assertJsonPath('data.receipt.replayed', true);
        $this->withHeaders($context->mobileAuthHeaders())->postJson($this->base().'/project-issues/'.$id.'/actions/resolve', ['expected_revision' => 1])
            ->assertConflict();
        self::assertSame(3, QualityDefect::query()->findOrFail($id)->getAttribute('row_version'));
    }

    public function test_sets_open_exact_revision_and_reject_conflicting_updates(): void
    {
        [$context, $project, $version] = $this->fixture();
        $headers = $context->mobileAuthHeaders();
        $set = $this->withHeaders($headers)->postJson($this->base().'/model-sets', ['project_id' => $project->id, 'title' => 'Набор', 'version_ids' => [$version->id]])->assertCreated();
        $id = $set->json('data.id');
        $new = $version->replicate(); $new->version_number = '2'; $new->save();
        $this->withHeaders($headers)->patchJson($this->base().'/model-sets/'.$id, ['expected_revision' => 1, 'version_ids' => [$new->id]])
            ->assertOk()->assertJsonPath('data.revision', 2);
        $this->withHeaders($headers)->patchJson($this->base().'/model-sets/'.$id, ['expected_revision' => 1, 'version_ids' => [$version->id]])->assertConflict();
        $this->withHeaders($headers)->getJson($this->base().'/model-sets/'.$id.'/revisions/1/open')->assertOk()->assertJsonPath('data.models.0', $version->id);
    }

    public function test_model_actions_check_project_access_once_and_recheck_revocation(): void
    {
        [$context, $project] = $this->fixture();
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => UserProjectAccessMode::ASSIGNED_PROJECTS->value]);
        $access = app(\App\Services\Mobile\MobileDesignManagementAccess::class);
        $permissions = ['design-management.models.edit', 'design-management.review'];
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $result = $access->permissions($context->user, $context->organization->id, $project->id, $permissions);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame(array_fill_keys($permissions, true), $result);
        $projectQueries = array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'from "projects"') && str_contains($query['query'], 'exists'));
        $this->assertCount(1, $projectQueries);
        DB::table('project_user')->where('user_id', $context->user->id)->where('project_id', $project->id)->update(['is_active' => false]);
        $this->assertSame(array_fill_keys($permissions, false), $access->permissions($context->user, $context->organization->id, $project->id, $permissions));
    }

    public function test_viewer_returns_signed_files_without_loading_unused_relations(): void
    {
        [$context, $project, $version] = $this->fixture();
        $path = 'org-'.$context->organization->id.'/viewer/model.frag';
        DesignModelDerivative::query()->create(['organization_id' => $context->organization->id, 'project_id' => $project->id,
            'version_id' => $version->id, 'created_by' => $context->user->id, 'viewer_provider' => 'thatopen',
            'derivative_format' => 'thatopen_frag', 'derivative_file_path' => $path, 'status' => 'ready',
            'metadata' => ['converter_version' => config('design_management.viewer_converter_version')]]);
        $files = $this->mock(FileService::class);
        $files->shouldReceive('temporaryUrl')->once()->with($version->source_file_path, 60, \Mockery::type(\App\Models\Organization::class))->andReturn('https://files.example.test/source');
        $files->shouldReceive('temporaryUrl')->once()->with($path, 60, \Mockery::type(\App\Models\Organization::class))->andReturn('https://files.example.test/viewer');
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->withHeaders($context->mobileAuthHeaders())->getJson($this->base().'/model-versions/'.$version->id.'/viewer')
                ->assertOk()->assertJsonPath('data.version.id', $version->id)
                ->assertJsonPath('data.source.download_url', 'https://files.example.test/source')
                ->assertJsonPath('data.derivative.download_url', 'https://files.example.test/viewer');
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $sql = implode("\n", array_column($queries, 'query'));
        $this->assertStringNotContainsString('from "design_document_sheets"', $sql);
        $this->assertStringNotContainsString('from "design_package_sections"', $sql);
        $this->assertCount(1, array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'from "design_model_derivatives"')));
    }

    public function test_foreign_and_unassigned_versions_are_hidden(): void
    {
        [$context, , $version] = $this->fixture();
        [$foreign, , $foreignVersion] = $this->fixture();
        $this->withHeaders($context->mobileAuthHeaders())->getJson($this->base().'/model-versions/'.$foreignVersion->id.'/viewer')->assertNotFound();
        $foreign->organization->users()->updateExistingPivot($foreign->user->id, ['project_access_mode' => UserProjectAccessMode::ASSIGNED_PROJECTS->value]);
        DB::table('project_user')->where('user_id', $foreign->user->id)->update(['is_active' => false]);
        $this->withHeaders($foreign->mobileAuthHeaders())->getJson($this->base().'/model-versions/'.$foreignVersion->id.'/elements')->assertNotFound();
        $this->withHeaders($context->mobileAuthHeaders())->getJson($this->base().'/model-versions/'.$version->id.'/elements/71')
            ->assertOk()->assertJsonPath('data.express_id', 71);
    }

    public function test_offline_manifest_refreshes_signed_urls_without_changing_exact_generation(): void
    {
        [$context, $project, $version] = $this->fixture();
        $disk = Storage::fake('s3');
        $generation = 'f0e4bbf2-a5aa-4f70-8212-060386d570ca';
        $prefix = 'org-'.$context->organization->id.'/pir/projects/'.$project->id.'/packages/'.$version->artifact->package_id.'/models/'.$version->id.'/viewer/'.$generation.'/';
        $disk->put($prefix.'model.frag', 'frag');
        $disk->put($prefix.'properties.ndjson', '{"express_id":71}');
        DesignModelDerivative::query()->create(['organization_id' => $context->organization->id, 'project_id' => $project->id,
            'version_id' => $version->id, 'created_by' => $context->user->id, 'viewer_provider' => 'thatopen',
            'derivative_format' => 'thatopen_frag', 'derivative_file_path' => $prefix.'model.frag', 'status' => 'ready',
            'metadata' => ['converter_version' => config('design_management.viewer_converter_version'), 'generation' => $generation,
                'runtime' => ['fragments' => '3.1.0'], 'offline_package' => ['schema_version' => 1, 'generation' => $generation,
                    'geometry' => ['path' => $prefix.'model.frag', 'size' => 4, 'sha256' => hash('sha256', 'frag'), 'mime' => 'application/octet-stream'],
                    'properties' => ['path' => $prefix.'properties.ndjson', 'size' => 17, 'sha256' => hash('sha256', '{"express_id":71}'), 'mime' => 'application/x-ndjson']]]]);
        $number = 0;
        $files = $this->mock(FileService::class);
        $files->shouldReceive('disk')->andReturn($disk);
        $files->shouldReceive('temporaryUrl')->andReturnUsing(function () use (&$number): string { return 'https://files.example.test/signed-'.(++$number); });
        $headers = $context->mobileAuthHeaders();
        $path = $this->base().'/model-versions/'.$version->id.'/offline-package';
        $first = $this->withHeaders($headers)->getJson($path)->assertOk()->assertJsonPath('data.generation', $generation)
            ->assertJsonPath('data.available_actions.1.key', 'create_issue');
        $second = $this->withHeaders($headers)->getJson($path)->assertOk()->assertJsonPath('data.generation', $generation);
        self::assertNotSame($first->json('data.geometry.url'), $second->json('data.geometry.url'));
        self::assertSame($first->json('data.geometry.sha256'), $second->json('data.geometry.sha256'));
    }

    public function test_session_adapters_keep_native_auth_and_exact_pinned_revision(): void
    {
        [$context, $project, $version] = $this->fixture();
        config(['design_management.session_cache_store' => 'array']);
        Event::fake([DesignModelSessionTransientEvent::class]);
        $headers = $context->mobileAuthHeaders();
        $set = $this->withHeaders($headers)->postJson($this->base().'/model-sets', ['project_id' => $project->id, 'title' => 'Сессия', 'version_ids' => [$version->id]])->assertCreated();
        $open = $this->withHeaders($headers)->getJson($this->base().'/model-sets/'.$set->json('data.id').'/revisions/1/open')->assertOk();
        $session = $this->withHeaders($headers)->postJson($this->base().'/model-sessions', ['project_id' => $project->id,
            'title' => 'Совместный просмотр', 'model_set_revision_id' => $open->json('data.model_set_revision_id')])
            ->assertCreated()->assertJsonPath('data.models.0', $version->id)->assertJsonPath('data.realtime.auth_endpoint', '/api/v1/mobile/broadcasting/auth');
        $id = $session->json('data.id');
        $this->withHeaders($headers)->getJson($this->base().'/model-sessions?project_id='.$project->id)
            ->assertOk()->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.model_set_id', $set->json('data.id'))
            ->assertJsonPath('data.0.model_set_revision_id', $open->json('data.model_set_revision_id'))
            ->assertJsonPath('data.0.model_set_revision', 1)
            ->assertJsonPath('data.0.models.0', $version->id);
        $this->withHeaders($headers)->postJson($this->base().'/model-sessions/'.$id.'/events', ['schema_version' => 2,
            'type' => 'heartbeat', 'client_id' => 'native_client_1', 'sequence' => 1, 'payload' => null])
            ->assertOk()->assertJsonPath('data.sender.id', $context->user->id);
        $this->withHeaders($headers)->getJson($this->base().'/model-sessions/'.$id.'/participants')
            ->assertOk()->assertJsonPath('data.0.client_id', 'native_client_1');
        $this->withHeaders($headers)->getJson($this->base().'/model-sessions/'.$id.'/view-state/native_client_1')->assertOk()->assertJsonPath('data', null);
    }

    private function fixture(): array
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        DB::table('project_user')->insert(['project_id' => $project->id, 'user_id' => $context->user->id, 'role' => 'project_manager', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        UserRoleAssignment::assignRole($context->user, 'project_manager', AuthorizationContext::getProjectContext((int) $project->id, (int) $context->organization->id));
        $this->entitlements($context);
        self::assertTrue(app(AuthorizationService::class)->can($context->user, 'design-management.models.view',
            ['organization_id' => $context->organization->id, 'project_id' => $project->id, 'strict_project_scope' => true]));
        $package = DesignPackage::query()->create(['organization_id' => $context->organization->id, 'project_id' => $project->id,
            'created_by' => $context->user->id, 'updated_by' => $context->user->id, 'title' => 'Комплект', 'status' => 'draft']);
        $artifact = DesignArtifact::query()->create(['organization_id' => $context->organization->id, 'project_id' => $project->id,
            'package_id' => $package->id, 'created_by' => $context->user->id, 'title' => 'Модель', 'artifact_type' => 'model']);
        $version = DesignArtifactVersion::query()->create(['organization_id' => $context->organization->id, 'project_id' => $project->id,
            'artifact_id' => $artifact->id, 'uploaded_by' => $context->user->id, 'created_by' => $context->user->id,
            'title' => 'Версия', 'version_number' => '1', 'source_file_path' => 'org-'.$context->organization->id.'/model.ifc',
            'source_original_name' => 'model.ifc', 'source_mime_type' => 'application/x-step', 'source_size_bytes' => 1, 'file_format' => 'ifc', 'is_current' => true]);
        DesignIfcModelElement::query()->create(['organization_id' => $context->organization->id, 'project_id' => $project->id,
            'version_id' => $version->id, 'express_id' => 71, 'name' => 'Wall', 'properties' => []]);

        return [$context, $project, $version];
    }

    private function entitlements(AdminApiTestContext $context): void
    {
        $modules = [
            [new DesignManagementModule(), 'ModuleList/features/design-management.json'],
            [new ExecutiveDocumentationModule(), 'ModuleList/features/executive-documentation.json'],
            [new ProjectManagementModule(), 'ModuleList/features/project-management.json'],
            [new ContractManagementModule(), 'ModuleList/features/contract-management.json'],
            [new FileManagementModule(), 'ModuleList/addons/file-management.json'],
            [new ReportTemplatesModule(), 'ModuleList/services/report-templates.json'],
        ];
        foreach ($modules as [$module, $configFile]) {
            $manifest = $module->getManifest();
            Module::query()->updateOrCreate(['slug' => $module->getSlug()], [
                'name' => $module->getName(), 'version' => $module->getVersion(), 'type' => $module->getType()->value,
                'billing_model' => $module->getBillingModel()->value, 'category' => $manifest['category'] ?? 'construction',
                'description' => $module->getDescription(), 'features' => $module->getFeatures(), 'permissions' => $module->getPermissions(),
                'dependencies' => $module->getDependencies(), 'conflicts' => $module->getConflicts(), 'limits' => $module->getLimits(),
                'class_name' => $module::class, 'config_file' => $configFile, 'display_order' => $manifest['display_order'] ?? 0,
                'is_active' => true, 'is_system_module' => false,
            ]);
        }
        $now = now();
        $account = OrganizationCommercialAccount::query()->create([
            'organization_id' => $context->organization->id, 'responsible_user_id' => $context->user->id,
            'status' => 'active', 'offer_type' => 'packages', 'quote_version' => 1,
            'current_period_start_at' => $now, 'current_period_end_at' => $now->copy()->addDays(30),
        ]);
        OrganizationPackageSubscription::query()->create([
            'organization_id' => $context->organization->id, 'commercial_account_id' => $account->id,
            'package_slug' => 'working-entry', 'status' => 'active', 'access_source' => 'paid_package', 'price_paid' => 39900,
            'current_period_start_at' => $now, 'current_period_end_at' => $now->copy()->addDays(30),
        ]);
        $access = app(AccessController::class);
        $access->clearAccessCache((int) $context->organization->id);
        self::assertTrue($access->hasModuleAccess((int) $context->organization->id, 'design-management'));
    }

    private function base(): string
    {
        return '/api/v1/mobile/design-management';
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('snapshot.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
    }
}
