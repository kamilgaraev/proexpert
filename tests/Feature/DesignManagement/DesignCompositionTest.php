<?php

declare(strict_types=1);

namespace Tests\Feature\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Models\DesignCompositionRevision;
use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignCompositionRevisionResource;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Services\DesignCompletenessService;
use App\BusinessModules\Features\DesignManagement\Services\DesignCompositionService;
use App\BusinessModules\Features\DesignManagement\Services\DesignManagementService;
use App\BusinessModules\Features\DesignManagement\Services\LegacyDesignCompositionBackfillService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use DomainException;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class DesignCompositionTest extends TestCase
{
    public function test_backfill_keeps_document_requirements_in_every_stage_and_in_the_next_revision(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowCompositionPermissions();
        $documents = [[
            'document_code' => 'DOC-01', 'document_title' => 'Существующий документ',
            'artifact_type' => 'drawing_set', 'required' => true, 'allowed_formats' => ['pdf'],
            'sheet_registry_required' => true, 'normative_reference' => 'Исходное задание', 'source' => 'custom',
        ]];
        foreach (['pd' => 'sections', 'rd' => 'document_groups', 'survey' => 'items', 'bim' => 'items'] as $stage => $key) {
            $package = $this->package($context->user, $project, $stage);
            $section = DesignPackageSection::query()->create([
                'organization_id' => $package->organization_id, 'project_id' => $package->project_id,
                'package_id' => $package->id, 'code' => 'AR', 'title' => 'Существующий состав',
                'project_stage' => $stage, 'required' => true, 'metadata' => ['documents' => $documents],
            ]);
            app(LegacyDesignCompositionBackfillService::class)->run();
            $revision = DesignCompositionRevision::query()->where('package_id', $package->id)->sole();
            $this->assertEquals($documents, $revision->composition[$key][0]['documents']);
            $this->assertSame($section->id, $revision->composition[$key][0]['legacy_section_id']);
            $next = app(DesignCompositionService::class)->createRevision($package->fresh(), $context->user, [
                'expected_revision' => 1, 'composition' => $revision->composition,
            ]);
            $this->assertEquals($documents, $next->composition[$key][0]['documents']);
            $this->assertEquals($documents, $section->fresh()->metadata['documents']);
            $this->assertSame(1, DesignPackageSection::query()->where('package_id', $package->id)->count());
        }
    }

    public function test_backfill_does_not_skip_packages_when_the_pending_set_shrinks_between_batches(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $row = [
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'created_by' => $context->user->id, 'updated_by' => $context->user->id,
            'title' => 'Старый комплект', 'project_stage' => 'pd', 'status' => 'draft',
            'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ];
        foreach (array_chunk(array_fill(0, 1001, $row), 100) as $rows) {
            \Illuminate\Support\Facades\DB::table('design_packages')->insert($rows);
        }
        $packages = DesignPackage::query()->where('project_id', $project->id);
        $this->assertSame(1001, (clone $packages)->whereNull('composition_revision_id')->count());

        app(LegacyDesignCompositionBackfillService::class)->run();

        $this->assertSame(0, (clone $packages)->whereNull('composition_revision_id')->count());
        $revisions = DesignCompositionRevision::query()->where('project_id', $project->id)->orderBy('package_id')->pluck('id', 'package_id')->all();
        $this->assertCount(1001, $revisions);
        $this->assertSame(1001, (clone $packages)->where('status', 'draft')->count());
        app(LegacyDesignCompositionBackfillService::class)->run();
        $this->assertSame($revisions, DesignCompositionRevision::query()->where('project_id', $project->id)->orderBy('package_id')->pluck('id', 'package_id')->all());
    }

    public function test_available_actions_follow_the_current_package_and_composition_state(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $package = $this->package($context->user, $project, 'pd');
        $this->allowCompositionPermissions();
        request()->setUserResolver(static fn () => $context->user);
        $service = app(DesignCompositionService::class);
        $revision = $service->createRevision($package, $context->user, $this->pdPayload($project->id));
        $actions = static fn (DesignCompositionRevision $value): array => (new DesignCompositionRevisionResource($value))->toArray(request())['available_actions'];

        $this->assertSame(['approve', 'create_revision', 'needs_review', 'exclusions'], $actions($revision));
        $approved = $service->approve($revision, $context->user);
        $this->assertSame(['create_revision', 'needs_review'], $actions($approved));
        $next = $service->createRevision($package, $context->user, [...$this->pdPayload($project->id), 'expected_revision' => 1]);
        $this->assertSame([], $actions($approved));
        foreach (['issued', 'archived'] as $status) {
            $package->update(['status' => $status]);
            $this->assertSame([], $actions($next));
        }
        $package->update(['status' => 'draft']);
        $this->mock(AuthorizationService::class, static function (MockInterface $mock): void {
            $mock->shouldReceive('can')->andReturn(false);
        });
        $this->assertSame([], $actions($next));
    }

    public function test_stale_draft_cannot_exclude_from_concurrently_approved_revision(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowCompositionPermissions();
        $service = app(DesignCompositionService::class);
        $draft = $service->createRevision($this->package($context->user, $project, 'pd'), $context->user, [
            'composition' => ['sections' => [['code' => 'AR']]],
        ]);
        $service->approve($draft->fresh(), $context->user);

        try {
            $service->exclude($draft, $context->user, ['item_key' => 'AR', 'reason' => 'Изменение задания']);
            self::fail('An approved revision must reject exclusion from a stale draft instance.');
        } catch (DomainException $exception) {
            self::assertSame(trans_message('design_composition.errors.approved_revision_locked'), $exception->getMessage());
        }
        self::assertSame(0, $draft->exclusions()->count());
        self::assertSame('approved', $draft->fresh()->status);
    }

    public function test_pd_preview_and_created_package_keep_only_selected_sections(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowCompositionPermissions();
        $payload = $this->pdPayload($project->id);
        $preview = app(DesignCompositionService::class)->preview($context->organization->id, $context->user, $payload);
        $package = app(DesignManagementService::class)->createPackage($context->organization->id, $context->user->id, array_merge($payload, ['title' => 'АР']));
        $revision = app(DesignCompositionService::class)->createRevision($package, $context->user, ['composition' => $preview]);

        $this->assertEquals($preview, $revision->composition);
        $this->assertSame(['AR'], DesignPackageSection::query()->where('package_id', $package->id)->pluck('code')->all());
    }

    public function test_revision_preserves_stage_and_edit_permission_does_not_allow_approval(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('can')->andReturnUsing(
                static fn ($actor, string $permission): bool => $permission === 'design-management.composition.edit'
            );
        });
        $package = $this->package($context->user, $project, 'pd');
        $service = app(DesignCompositionService::class);
        $revision = $service->createRevision($package, $context->user, [
            'project_stage' => 'rd',
            'composition' => ['project_stage' => 'rd', 'sections' => [['code' => 'AR']]],
        ]);
        self::assertSame('pd', $revision->composition['project_stage']);
        self::assertSame('pd', $package->fresh()->getRawOriginal('project_stage'));
        try {
            $service->approve($revision, $context->user);
            self::fail('Composition editing must not grant approval permission.');
        } catch (DomainException) {
            self::assertSame('draft', $revision->fresh()->status);
            self::assertNull($revision->fresh()->approved_by);
            self::assertNull($revision->fresh()->approved_at);
        }
    }

    public function test_rd_requires_one_brand_and_document_groups(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $this->allowCompositionPermissions();
        $service = app(DesignCompositionService::class);
        $this->expectException(DomainException::class);
        $service->preview($context->organization->id, $context->user, ['project_id' => 1, 'project_stage' => 'rd', 'composition' => ['brand' => '', 'document_groups' => []]]);
    }

    public function test_service_rejects_unauthorized_actor(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void { $mock->shouldReceive('can')->andReturn(false); });
        $this->expectException(DomainException::class);
        app(DesignCompositionService::class)->preview($context->organization->id, $context->user, $this->pdPayload(1));
    }

    public function test_approved_revision_becomes_stale_after_new_draft(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowCompositionPermissions();
        $package = $this->package($context->user, $project, 'pd');
        $service = app(DesignCompositionService::class);
        $first = $service->createRevision($package, $context->user, ['composition' => ['sections' => [['code' => 'AR']]]]);
        $service->approve($first, $context->user);
        $check = app(DesignCompletenessService::class)->run($package->fresh(), $context->user->id);
        $second = $service->createRevision($package->fresh(), $context->user, ['composition' => ['sections' => [['code' => 'AR']]], 'expected_revision' => 1]);

        $this->assertSame('draft', $second->status);
        $this->assertFalse(app(DesignCompletenessService::class)->isFreshForPackage($package->fresh(), $check));
    }

    public function test_existing_composition_requires_the_actual_revision_even_without_http_validation(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowCompositionPermissions();
        $package = $this->package($context->user, $project, 'pd');
        $service = app(DesignCompositionService::class);
        $composition = ['sections' => [['code' => 'AR']]];
        $first = $service->createRevision($package, $context->user, ['composition' => $composition]);
        foreach ([[], ['expected_revision' => null], ['expected_revision' => 0]] as $token) {
            try {
                $service->createRevision($package->fresh(), $context->user, ['composition' => $composition] + $token);
                $this->fail('A revision without a matching token was accepted');
            } catch (\DomainException $exception) {
                $this->assertSame(trans_message('design_composition.errors.revision_conflict'), $exception->getMessage());
            }
            $this->assertSame($first->id, $package->fresh()->composition_revision_id);
        }
        $second = $service->createRevision($package->fresh(), $context->user, ['composition' => $composition, 'expected_revision' => 1]);
        $this->assertSame(2, $second->revision_number);
    }

    public function test_revision_materializes_explicit_document_group_without_removing_history(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowCompositionPermissions();
        $package = $this->package($context->user, $project, 'rd', 'AR');
        $old = DesignPackageSection::query()->create(['organization_id' => $package->organization_id, 'project_id' => $project->id, 'package_id' => $package->id, 'code' => 'OLD', 'title' => 'История', 'project_stage' => 'rd', 'required' => false, 'metadata' => []]);
        app(DesignCompositionService::class)->createRevision($package, $context->user, ['composition' => ['brand' => 'AR', 'document_groups' => [['code' => 'AR', 'title' => 'АР', 'documents' => [['document_code' => 'AR-01']]]]]]);

        $this->assertDatabaseHas('design_package_sections', ['id' => $old->id, 'code' => 'OLD']);
        $this->assertSame('AR-01', DesignPackageSection::query()->where('package_id', $package->id)->where('code', 'AR')->sole()->metadata['documents'][0]['document_code']);
        $this->assertDatabaseHas('design_artifacts', ['package_id' => $package->id, 'document_code' => 'AR-01']);
    }

    public function test_revision_rejects_duplicate_section_codes(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowCompositionPermissions();
        $this->expectException(DomainException::class);
        app(DesignCompositionService::class)->createRevision($this->package($context->user, $project, 'pd'), $context->user, ['composition' => ['sections' => [['code' => 'AR'], ['code' => ' ar ']]]]);
    }

    public function test_legacy_ambiguous_rd_marker_does_not_issue_package(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $package = $this->package($context->user, $project, 'rd', null);
        $revision = DesignCompositionRevision::query()->create(['organization_id' => $package->organization_id, 'project_id' => $package->project_id, 'package_id' => $package->id, 'revision_number' => 1, 'status' => 'needs_review', 'composition' => ['project_stage' => 'rd', 'items' => []], 'fingerprint' => 'legacy-ambiguous', 'created_by' => $context->user->id]);
        $package->update(['composition_revision_id' => $revision->id, 'composition_status' => 'needs_review']);
        $check = app(DesignCompletenessService::class)->run($package->fresh(), $context->user->id);

        $this->assertSame('blocked', $check->status->value);
        $this->assertSame($package->id, $revision->package_id);
    }

    public function test_repeatable_backfill_preserves_issued_package_and_legacy_ids(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $package = $this->package($context->user, $project, 'rd', 'unknown');
        $package->update(['status' => 'issued']);
        $section = DesignPackageSection::query()->create(['organization_id' => $package->organization_id, 'project_id' => $package->project_id, 'package_id' => $package->id, 'code' => 'LEGACY', 'title' => 'Старый раздел', 'project_stage' => 'rd', 'required' => true, 'metadata' => []]);
        $artifact = $package->artifacts()->create([
            'organization_id' => $package->organization_id, 'project_id' => $package->project_id,
            'created_by' => $context->user->id, 'updated_by' => $context->user->id,
            'artifact_type' => 'drawing_set', 'title' => 'Старые чертежи', 'status' => 'active',
        ]);
        $versions = collect([1, 2])->map(fn (int $number) => $artifact->versions()->create([
            'organization_id' => $package->organization_id, 'project_id' => $package->project_id,
            'created_by' => $context->user->id, 'updated_by' => $context->user->id,
            'title' => 'Чертёж', 'version_number' => (string) $number, 'revision' => 'Р'.$number,
            'source_format' => 'pdf', 'source_file_path' => "org-{$package->organization_id}/old/plan-{$number}.pdf",
            'source_original_name' => "plan-{$number}.pdf", 'source_mime_type' => 'application/pdf',
            'source_size_bytes' => 128, 'status' => $number === 1 ? 'superseded' : 'current',
            'is_current' => $number === 2, 'metadata' => ['original_release' => '2026-01-01'],
        ])->fresh());
        $originalVersions = $versions->map(fn ($version): array => $version->getAttributes())->all();
        $event = \App\BusinessModules\Features\DesignManagement\Models\DesignWorkflowEvent::query()->create([
            'organization_id' => $package->organization_id, 'project_id' => $package->project_id,
            'package_id' => $package->id, 'actor_id' => $context->user->id,
            'action' => 'issue', 'from_status' => 'approved', 'to_status' => 'issued',
            'comment' => 'Первоначальный выпуск', 'metadata' => ['version_ids' => $versions->pluck('id')->all()],
        ])->fresh();
        $originalEvent = $event->getAttributes();
        app(LegacyDesignCompositionBackfillService::class)->run();
        $revision = DesignCompositionRevision::query()->where('package_id', $package->id)->firstOrFail();
        app(LegacyDesignCompositionBackfillService::class)->run();

        $this->assertSame('issued', $package->fresh()->status->value);
        $this->assertSame($revision->id, $package->fresh()->composition_revision_id);
        $this->assertSame('needs_review', $revision->status);
        $this->assertSame([$section->id], $revision->composition['legacy_snapshot']['section_ids']);
        $this->assertSame($revision->id, DesignCompositionRevision::query()->where('package_id', $package->id)->sole()->id);
        $this->assertSame($originalVersions, $versions->map(fn ($version): array => $version->fresh()->getAttributes())->all());
        $this->assertSame($originalEvent, $event->fresh()->getAttributes());
        $this->assertEqualsCanonicalizing($versions->pluck('id')->all(), array_column($revision->composition['legacy_snapshot']['versions'], 'id'));
        $this->assertSame($artifact->id, $revision->composition['legacy_snapshot']['artifacts'][0]['id']);
    }

    private function pdPayload(int $projectId): array { return ['project_id' => $projectId, 'project_stage' => 'pd', 'composition' => ['sections' => [['code' => 'AR', 'title' => 'Архитектурные решения', 'required' => true]]]]; }
    private function package(User $user, Project $project, string $stage, ?string $discipline = 'AR'): DesignPackage { return DesignPackage::query()->create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $user->id, 'updated_by' => $user->id, 'title' => 'Тестовый комплект', 'project_stage' => $stage, 'discipline' => $discipline, 'status' => 'draft', 'metadata' => []]); }
    private function allowCompositionPermissions(): void { $this->mock(AuthorizationService::class, function (MockInterface $mock): void { $mock->shouldReceive('can')->andReturn(true); }); }
}
