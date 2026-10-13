<?php

declare(strict_types=1);

namespace Tests\Feature\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Exceptions\DesignDrawingException;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignDrawingStroke;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Services\DesignDrawingStrokeService;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class DesignDrawingStrokeTest extends TestCase
{
    private array $denied = [];

    private bool $denyStrictReview = false;

    private bool $modulesActive = true;

    public function test_mobile_and_admin_share_autosaved_strokes_with_server_authorship_and_no_issue(): void
    {
        [$context, $version] = $this->fixture();
        $payload = $this->payload();
        $payload['author'] = ['id' => 999, 'name' => 'Подмена'];
        $mobile = '/api/v1/mobile/design-management/document-versions/'.$version->id.'/drawing-strokes';
        $this->withHeaders($context->mobileAuthHeaders())->postJson($mobile, $payload)->assertCreated()
            ->assertJsonPath('data.author.id', $context->user->id)
            ->assertJsonPath('data.author.name', $context->user->name)
            ->assertJsonPath('data.revision', 1)
            ->assertJsonPath('data.can_delete', true);
        $this->withHeaders($context->authHeaders())->getJson($this->endpoint($version))
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.revision', 1)->assertJsonPath('data.can_annotate', true)
            ->assertJsonPath('data.items.0.client_id', $payload['client_id'])
            ->assertJsonPath('data.items.0.annotations.page_number', 1);
        self::assertSame(0, QualityDefect::query()->where('project_id', $version->project_id)->count());
    }

    public function test_retry_is_idempotent_and_changed_content_conflicts(): void
    {
        [$context, $version] = $this->fixture();
        $payload = $this->payload();
        $this->withHeaders($context->authHeaders())->postJson($this->endpoint($version), $payload)->assertCreated();
        $this->postJson($this->endpoint($version), $payload)->assertCreated()->assertJsonPath('data.revision', 1);
        $payload['annotations']['strokes'][0]['points'][1]['x'] = 0.7;
        $this->postJson($this->endpoint($version), $payload)->assertConflict();
        self::assertSame(1, DesignDrawingStroke::query()->count());
        self::assertSame(1, (int) $version->fresh()->drawing_revision);
    }

    public function test_other_authors_are_visible_but_cannot_be_deleted_or_replaced(): void
    {
        [$context, $version] = $this->fixture();
        $other = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $context->organization->users()->attach($other->id, ['is_active' => true]);
        DB::table('project_user')->insert(['project_id' => $version->project_id, 'user_id' => $other->id,
            'role' => 'project_manager', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $service = app(DesignDrawingStrokeService::class);
        $payload = $this->payload();
        $service->store($other, (int) $context->organization->id, (int) $version->id, $payload);
        $service->store($context->user, (int) $context->organization->id, (int) $version->id, $this->payload());
        $this->withHeaders($context->authHeaders())->getJson($this->endpoint($version))
            ->assertOk()->assertJsonCount(2, 'data.items')->assertJsonPath('data.items.0.can_delete', false)
            ->assertJsonPath('data.items.0.author.id', $other->id)->assertJsonPath('data.items.1.can_delete', true);
        $this->deleteJson($this->endpoint($version).'/'.$payload['client_id'])->assertNotFound();
        $this->postJson($this->endpoint($version), $payload)->assertConflict();
        self::assertNull(DesignDrawingStroke::query()->where('client_id', $payload['client_id'])->first()->deleted_at);
    }

    public function test_undo_is_a_shared_tombstone_and_retries_never_resurrect_it(): void
    {
        [$context, $version] = $this->fixture();
        $payload = $this->payload();
        $url = $this->endpoint($version);
        $this->withHeaders($context->authHeaders())->postJson($url, $payload)->assertCreated();
        $this->deleteJson($url.'/'.$payload['client_id'])->assertOk()->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.annotations', null)->assertJsonPath('data.can_delete', false);
        $this->deleteJson($url.'/'.$payload['client_id'])->assertOk()->assertJsonPath('data.revision', 2);
        $this->postJson($url, $payload)->assertCreated()->assertJsonPath('data.annotations', null);
        $this->getJson($url.'?after_revision=1')->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.revision', 2)->assertJsonPath('data.items.0.annotations', null);
        $this->getJson($url.'?after_revision=2')->assertOk()->assertJsonCount(0, 'data.items');
        self::assertSame(2, (int) $version->fresh()->drawing_revision);
    }

    public function test_cancel_before_delayed_create_reserves_the_uuid_without_resurrection(): void
    {
        [$context, $version] = $this->fixture();
        $payload = $this->payload();
        $url = $this->endpoint($version);
        $this->withHeaders($context->authHeaders())->deleteJson($url.'/'.$payload['client_id'])
            ->assertOk()->assertJsonPath('data.revision', 1)->assertJsonPath('data.annotations', null);
        $this->postJson($url, $payload)->assertCreated()->assertJsonPath('data.revision', 1)
            ->assertJsonPath('data.annotations', null)->assertJsonPath('data.page_number', null);
        $this->deleteJson($url.'/'.$payload['client_id'])->assertOk()->assertJsonPath('data.revision', 1);
        self::assertSame(1, DesignDrawingStroke::query()->count());
        self::assertSame(0, DesignDrawingStroke::query()->whereNull('deleted_at')->count());
    }

    public function test_drawing_counter_does_not_change_document_freshness(): void
    {
        [$context, $version] = $this->fixture();
        $version->forceFill(['updated_at' => now()->subDay()])->saveQuietly();
        $before = $version->fresh()->getRawOriginal('updated_at');
        $payload = $this->payload();
        $url = $this->endpoint($version);
        $this->withHeaders($context->authHeaders())->postJson($url, $payload)->assertCreated();
        self::assertSame($before, $version->fresh()->getRawOriginal('updated_at'));
        $this->deleteJson($url.'/'.$payload['client_id'])->assertOk();
        self::assertSame($before, $version->fresh()->getRawOriginal('updated_at'));
        self::assertSame(2, (int) $version->fresh()->drawing_revision);
    }

    public function test_service_and_http_enforce_permissions_module_and_document_scope(): void
    {
        [$context, $version] = $this->fixture();
        $this->denyStrictReview = true;
        $this->withHeaders($context->authHeaders())->postJson($this->endpoint($version), $this->payload())->assertNotFound();
        $this->getJson($this->endpoint($version))->assertOk()->assertJsonPath('data.can_annotate', false);
        $this->denyStrictReview = false;
        $this->denied = ['design-management.documents.view'];
        $this->getJson($this->endpoint($version))->assertForbidden();
        $this->postJson($this->endpoint($version), $this->payload())->assertNotFound();
        $this->denied = [];
        $this->modulesActive = false;
        try {
            app(DesignDrawingStrokeService::class)->changes($context->user, (int) $context->organization->id, (int) $version->id);
            self::fail('Inactive modules must be rejected outside HTTP');
        } catch (DesignDrawingException $exception) {
            self::assertSame(403, $exception->getCode());
        }
        $this->modulesActive = true;
        $foreignProject = Project::factory()->create();
        $version->artifact->update(['project_id' => $foreignProject->id]);
        $this->getJson($this->endpoint($version))->assertNotFound();
        self::assertSame(0, DesignDrawingStroke::query()->count());
    }

    public function test_another_organization_cannot_read_or_create_strokes(): void
    {
        [, $version] = $this->fixture();
        $other = AdminApiTestContext::create(roleSlug: 'project_manager');
        $this->withHeaders($other->authHeaders())->getJson($this->endpoint($version))->assertNotFound();
        $this->postJson($this->endpoint($version), $this->payload())->assertNotFound();
        self::assertSame(0, DesignDrawingStroke::query()->count());
    }

    public function test_validation_rejects_unsupported_format_page_coordinates_and_multiple_strokes(): void
    {
        [$context, $version] = $this->fixture();
        $payload = $this->payload();
        $payload['annotations']['strokes'][0]['points'][0]['x'] = 1.01;
        $this->withHeaders($context->authHeaders())->postJson($this->endpoint($version), $payload)->assertUnprocessable();
        $payload = $this->payload();
        $payload['annotations']['page_number'] = 4;
        $this->postJson($this->endpoint($version), $payload)->assertUnprocessable();
        $payload = $this->payload();
        $payload['annotations']['strokes'][] = $payload['annotations']['strokes'][0];
        $this->postJson($this->endpoint($version), $payload)->assertUnprocessable();
        $version->update(['file_format' => 'ifc', 'source_mime_type' => 'application/x-step']);
        $this->postJson($this->endpoint($version), $this->payload())->assertNotFound();
        self::assertSame(0, DesignDrawingStroke::query()->count());
    }

    public function test_delta_pages_are_bounded_and_do_not_skip_changes(): void
    {
        [$context, $version] = $this->fixture();
        $rows = [];
        for ($revision = 1; $revision <= 201; $revision++) {
            $rows[] = ['organization_id' => $version->organization_id, 'project_id' => $version->project_id,
                'version_id' => $version->id, 'client_id' => (string) Str::uuid(),
                'author_id' => $context->user->id, 'author_name' => $context->user->name,
                'page_number' => 1, 'annotations' => json_encode($this->payload()['annotations'], JSON_THROW_ON_ERROR),
                'fingerprint' => str_repeat('a', 64), 'revision' => $revision, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('design_drawing_strokes')->insert($rows);
        $version->forceFill(['drawing_revision' => 201])->save();
        $this->withHeaders($context->authHeaders())->getJson($this->endpoint($version))
            ->assertOk()->assertJsonCount(200, 'data.items')->assertJsonPath('data.revision', 200)->assertJsonPath('data.has_more', true);
        $this->getJson($this->endpoint($version).'?after_revision=200')->assertOk()
            ->assertJsonCount(1, 'data.items')->assertJsonPath('data.revision', 201)->assertJsonPath('data.has_more', false);
        $this->getJson($this->endpoint($version).'?after_revision=202')->assertConflict();
    }

    private function fixture(): array
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        DB::table('project_user')->insert(['project_id' => $project->id, 'user_id' => $context->user->id,
            'role' => 'project_manager', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturnUsing(fn (): bool => $this->modulesActive);
        });
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface', 'hasRole')->andReturn(true);
            $mock->shouldReceive('can')->andReturnUsing(fn (User $user, string $permission, ?array $scope = null): bool => ! in_array($permission, $this->denied, true) && ! ($this->denyStrictReview
                    && $permission === 'design-management.review' && ($scope['strict_project_scope'] ?? false)));
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['project_manager']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(fn (User $user) => $user->roleAssignments()->where('is_active', true)->get());
        });
        $package = DesignPackage::query()->create(['organization_id' => $context->organization->id,
            'project_id' => $project->id, 'created_by' => $context->user->id, 'title' => 'Комплект', 'status' => 'draft']);
        $artifact = DesignArtifact::query()->create(['organization_id' => $context->organization->id,
            'project_id' => $project->id, 'package_id' => $package->id, 'created_by' => $context->user->id,
            'title' => 'Планы', 'artifact_type' => 'drawing_set']);
        $version = DesignArtifactVersion::query()->create(['organization_id' => $context->organization->id,
            'project_id' => $project->id, 'artifact_id' => $artifact->id, 'uploaded_by' => $context->user->id,
            'created_by' => $context->user->id, 'title' => 'Планы', 'version_number' => '1',
            'source_file_path' => 'org-'.$context->organization->id.'/plans.pdf', 'source_original_name' => 'plans.pdf',
            'file_format' => 'pdf', 'source_mime_type' => 'application/pdf', 'source_size_bytes' => 100, 'page_count' => 3]);

        return [$context, $version];
    }

    private function payload(): array
    {
        return ['client_id' => (string) Str::uuid(), 'annotations' => ['schema_version' => 1, 'page_number' => 1,
            'width' => 1000, 'height' => 700, 'strokes' => [['kind' => 'line', 'color' => '#e53935', 'width' => 3,
                'points' => [['x' => 0.1, 'y' => 0.2], ['x' => 0.8, 'y' => 0.9]]]]]];
    }

    private function endpoint(DesignArtifactVersion $version): string
    {
        return '/api/v1/admin/design-management/document-versions/'.$version->id.'/drawing-strokes';
    }
}
