<?php

declare(strict_types=1);

namespace Tests\Feature\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Models\BimConstructionProgressGroup;
use App\BusinessModules\Features\DesignManagement\Models\BimConstructionProgressGroupHistory;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Modules\Core\AccessController;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class BimConstructionProgressApiTest extends TestCase
{
    private AdminApiTestContext $context;
    private int $projectId;
    private int $versionId;
    private int $taskId;
    private int $workTypeId;
    private array $disabledModules = [];
    private array $deniedPermissions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $this->partialMock(AccessController::class, function ($mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturnUsing(fn ($organization, $module): bool => !in_array($module, $this->disabledModules, true));
        });
        $this->partialMock(AuthorizationService::class, function ($mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['project_manager']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(fn ($user) => $user->roleAssignments()->where('is_active', true)->get());
            $mock->shouldReceive('can')->andReturnUsing(fn ($actor, $permission): bool => !in_array($permission, $this->deniedPermissions, true));
        });
        $org = $this->context->organization->id;
        $user = $this->context->user->id;
        $this->projectId = Project::factory()->create(['organization_id' => $org])->id;
        Project::findOrFail($this->projectId)->users()->attach($user, ['role' => 'project_manager', 'is_active' => true, 'assigned_at' => now(), 'assigned_by_user_id' => $user]);
        $package = DB::table('design_packages')->insertGetId(['organization_id' => $org, 'project_id' => $this->projectId, 'title' => 'BIM test', 'project_stage' => 'bim', 'created_by' => $user, 'updated_by' => $user]);
        $artifact = DB::table('design_artifacts')->insertGetId(['organization_id' => $org, 'project_id' => $this->projectId, 'package_id' => $package, 'title' => 'AR', 'artifact_type' => 'ifc_model']);
        $this->versionId = DB::table('design_artifact_versions')->insertGetId(['organization_id' => $org, 'project_id' => $this->projectId, 'artifact_id' => $artifact, 'title' => 'AR1', 'version_number' => '1', 'file_format' => 'ifc', 'source_file_path' => 'test/ar.ifc', 'source_original_name' => 'AR.ifc', 'source_mime_type' => 'application/octet-stream', 'source_size_bytes' => 100]);
        foreach ([101, 102] as $element) {
            DB::table('design_ifc_model_elements')->insert(['organization_id' => $org, 'project_id' => $this->projectId, 'version_id' => $this->versionId, 'express_id' => $element]);
        }
        $schedule = DB::table('project_schedules')->insertGetId(['organization_id' => $org, 'project_id' => $this->projectId, 'created_by_user_id' => $user, 'name' => 'Schedule', 'planned_start_date' => '2026-01-01', 'planned_end_date' => '2026-02-01', 'status' => 'active']);
        $this->taskId = DB::table('schedule_tasks')->insertGetId(['organization_id' => $org, 'schedule_id' => $schedule, 'created_by_user_id' => $user, 'name' => 'Walls', 'task_type' => 'task', 'quantity' => 10, 'planned_start_date' => '2026-01-01', 'planned_end_date' => '2026-02-01', 'planned_duration_days' => 31]);
        $this->workTypeId = DB::table('work_types')->insertGetId(['organization_id' => $org, 'name' => 'Walls']);
    }

    public function test_confirmed_task_work_updates_bim_without_another_link_or_mutating_the_task(): void
    {
        $this->postJson($this->url(), $this->payload(), $this->context->authHeaders())->assertCreated();
        $draft = $this->work(10, 'draft', '2026-01-01');
        $this->getJson($this->url(), $this->context->authHeaders())->assertOk()->assertJsonPath('data.groups.0.actual.completed', false);
        DB::table('completed_works')->where('id', $draft)->update(['status' => 'confirmed', 'completed_quantity' => 6]);
        $this->getJson($this->url(), $this->context->authHeaders())->assertOk()->assertJsonPath('data.groups.0.actual.progress_percent', 60)->assertJsonPath('data.groups.0.actual.completed', false);
        $remaining = $this->work(4, 'confirmed', '2026-01-15');
        $this->work(2, 'confirmed', '2026-02-15');
        $this->getJson($this->url(), $this->context->authHeaders())->assertOk()->assertJsonPath('data.groups.0.actual.completed', true)->assertJsonPath('data.groups.0.actual.completed_at', '2026-01-15');
        DB::table('completed_works')->where('id', $remaining)->update(['status' => 'cancelled']);
        $this->getJson($this->url(), $this->context->authHeaders())->assertOk()->assertJsonPath('data.groups.0.actual.completed', false);
        self::assertSame(0, DB::table('design_source_links')->count());
        self::assertSame(0.0, (float) DB::table('schedule_tasks')->where('id', $this->taskId)->value('completed_quantity'));
        self::assertSame(3, DB::table('completed_works')->count());
    }

    public function test_modules_and_permissions_are_optional_and_do_not_delete_bindings(): void
    {
        $this->postJson($this->url(), $this->payload(), $this->context->authHeaders())->assertCreated();
        $this->disabledModules = ['workflow-management'];
        $this->getJson($this->url(), $this->context->authHeaders())->assertOk()->assertJsonPath('data.capabilities.schedule', true)->assertJsonPath('data.capabilities.actual', false)->assertJsonPath('data.groups.0.actual', null);
        $this->disabledModules = ['schedule-management'];
        $this->getJson($this->url(), $this->context->authHeaders())->assertOk()->assertJsonPath('data.capabilities.schedule', false)->assertJsonPath('data.capabilities.manage', false)->assertJsonPath('data.groups', []);
        self::assertSame(1, BimConstructionProgressGroup::count());
        $this->disabledModules = [];
        $this->deniedPermissions = ['completed_works.view', 'design-management.models.edit'];
        $this->getJson($this->url(), $this->context->authHeaders())->assertOk()->assertJsonPath('data.capabilities.actual', false)->assertJsonPath('data.capabilities.manage', false);
        $this->postJson($this->url(), $this->payload('denied-key'), $this->context->authHeaders())->assertForbidden();
        $this->deniedPermissions = [];
        $this->disabledModules = ['design-management'];
        $this->getJson($this->url(), $this->context->authHeaders())->assertStatus(403);
        self::assertSame(1, BimConstructionProgressGroup::count());
    }

    public function test_idempotency_revision_and_archiving_preserve_history(): void
    {
        $id = $this->postJson($this->url(), $this->payload(), $this->context->authHeaders())->assertCreated()->json('data.id');
        $this->postJson($this->url(), $this->payload(), $this->context->authHeaders())->assertCreated()->assertJsonPath('data.id', $id);
        self::assertSame(1, BimConstructionProgressGroup::count());
        $this->patchJson($this->url().'/'.$id, ['revision' => 1, 'title' => 'Revised'], $this->context->authHeaders())->assertOk()->assertJsonPath('data.revision', 2);
        $this->patchJson($this->url().'/'.$id, ['revision' => 1, 'title' => 'Stale'], $this->context->authHeaders())->assertConflict();
        $this->deleteJson($this->url().'/'.$id, ['revision' => 2, 'reason' => 'Task changed'], $this->context->authHeaders())->assertOk();
        $this->postJson($this->url(), $this->payload(), $this->context->authHeaders())->assertStatus(422);
        self::assertSame(1, BimConstructionProgressGroup::count());
        self::assertSame(3, BimConstructionProgressGroupHistory::count());
        $this->postJson($this->url(), $this->payload('replacement-key'), $this->context->authHeaders())->assertCreated();
        self::assertSame(2, BimConstructionProgressGroup::count());
    }

    public function test_foreign_task_and_element_are_rejected_and_moved_task_is_not_disclosed(): void
    {
        $other = Project::factory()->create(['organization_id' => $this->context->organization->id]);
        $scheduleId = DB::table('schedule_tasks')->where('id', $this->taskId)->value('schedule_id');
        $this->postJson($this->url(), array_replace($this->payload(), ['element_ids' => [999999]]), $this->context->authHeaders())->assertStatus(422);
        $id = $this->postJson($this->url(), $this->payload(), $this->context->authHeaders())->assertCreated()->json('data.id');
        DB::table('project_schedules')->where('id', $scheduleId)->update(['project_id' => $other->id]);
        $this->getJson($this->url(), $this->context->authHeaders())->assertOk()->assertJsonPath('data.groups.0.task', null)->assertJsonPath('data.groups.0.planned_date', null)->assertJsonPath('data.groups.0.actual.completed', false);
        $this->postJson($this->url(), $this->payload('foreign-task-key'), $this->context->authHeaders())->assertStatus(422);
        $this->postJson($this->url(), $this->payload(), $this->context->authHeaders())->assertStatus(422);
        $this->patchJson($this->url().'/'.$id, ['revision' => 1, 'title' => 'Moved'], $this->context->authHeaders())->assertStatus(422);
    }

    public function test_journal_work_requires_current_approval_and_the_same_project(): void
    {
        $this->postJson($this->url(), $this->payload(), $this->context->authHeaders())->assertCreated();
        $journal = DB::table('construction_journals')->insertGetId(['organization_id' => $this->context->organization->id, 'project_id' => $this->projectId, 'name' => 'Journal', 'start_date' => '2026-01-01', 'created_by_user_id' => $this->context->user->id]);
        $entry = DB::table('construction_journal_entries')->insertGetId(['journal_id' => $journal, 'entry_date' => '2026-01-15', 'entry_number' => 1, 'work_description' => 'Walls', 'status' => 'draft', 'created_by_user_id' => $this->context->user->id]);
        $work = $this->work(10, 'confirmed', '2026-01-15');
        DB::table('completed_works')->where('id', $work)->update(['journal_entry_id' => $entry]);
        $this->getJson($this->url(), $this->context->authHeaders())->assertOk()->assertJsonPath('data.groups.0.actual.confirmed_quantity', 0);
        DB::table('construction_journal_entries')->where('id', $entry)->update(['status' => 'approved']);
        $this->getJson($this->url(), $this->context->authHeaders())->assertOk()->assertJsonPath('data.groups.0.actual.completed', true);
        DB::table('construction_journal_entries')->where('id', $entry)->update(['status' => 'rejected']);
        $this->getJson($this->url(), $this->context->authHeaders())->assertOk()->assertJsonPath('data.groups.0.actual.completed', false);
        DB::table('construction_journal_entries')->where('id', $entry)->update(['status' => 'approved']);
        $other = Project::factory()->create(['organization_id' => $this->context->organization->id]);
        DB::table('construction_journals')->where('id', $journal)->update(['project_id' => $other->id]);
        $this->getJson($this->url(), $this->context->authHeaders())->assertOk()->assertJsonPath('data.groups.0.actual.confirmed_quantity', 0);
    }

    public function test_project_membership_and_version_organization_are_enforced(): void
    {
        $this->postJson($this->url(), $this->payload(), $this->context->authHeaders())->assertCreated();
        $this->deniedPermissions = ['schedule.view'];
        $this->getJson($this->url(), $this->context->authHeaders())->assertOk()->assertJsonPath('data.groups', [])->assertJsonPath('data.capabilities.schedule', false);
        $this->deniedPermissions = [];
        $this->context->organization->users()->updateExistingPivot($this->context->user->id, ['is_owner' => false, 'project_access_mode' => 'assigned_projects']);
        Project::findOrFail($this->projectId)->users()->detach($this->context->user->id);
        $this->getJson($this->url(), $this->context->authHeaders())->assertStatus(422);
        $otherContext = AdminApiTestContext::create(roleSlug: 'project_manager');
        $this->getJson($this->url(), $otherContext->authHeaders())->assertStatus(422);
        self::assertSame(1, BimConstructionProgressGroup::count());
    }

    private function url(): string
    {
        return '/api/v1/admin/design-management/model-versions/'.$this->versionId.'/construction-progress';
    }

    private function payload(string $key = 'original-key'): array
    {
        return ['title' => 'Second floor walls', 'floor' => '2', 'zone' => 'A', 'work_kind' => 'Walls', 'task_id' => $this->taskId, 'element_ids' => [101, 102], 'idempotency_key' => $key];
    }

    private function work(float $quantity, string $status, string $date): int
    {
        return DB::table('completed_works')->insertGetId(['organization_id' => $this->context->organization->id, 'project_id' => $this->projectId, 'work_type_id' => $this->workTypeId, 'user_id' => $this->context->user->id, 'schedule_task_id' => $this->taskId, 'quantity' => $quantity, 'completed_quantity' => $quantity, 'completion_date' => $date, 'status' => $status]);
    }
}
