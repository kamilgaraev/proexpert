<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\ScheduleManagement\Models\DailyWorkPlan;
use App\BusinessModules\Features\ScheduleManagement\Models\LookaheadPlan;
use App\BusinessModules\Features\ScheduleManagement\Models\LookaheadPlanTask;
use App\BusinessModules\Features\ScheduleManagement\Models\WorkConstraint;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyIncident;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\Enums\EstimatePositionItemType;
use App\Models\ConstructionJournal;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScheduleDailyPlanningWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_foreman_can_see_daily_plan_record_fact_and_submit_result(): void
    {
        $this->withoutMiddleware();

        $organization = Organization::factory()->verified()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($user->id, ['is_owner' => false, 'is_active' => true]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $project->users()->attach($user->id, ['role' => 'foreman']);
        $estimate = Estimate::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'EST-DP-1',
            'name' => 'Tower estimate',
            'status' => 'approved',
            'estimate_date' => '2026-06-01',
            'total_amount' => 10000,
        ]);
        $estimateItem = EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'position_number' => '1',
            'item_type' => EstimatePositionItemType::WORK->value,
            'name' => 'Foundation reinforcement',
            'quantity' => 10,
            'quantity_total' => 10,
            'unit_price' => 1000,
            'total_amount' => 10000,
        ]);

        $schedule = ProjectSchedule::query()->create([
            'project_id' => $project->id,
            'organization_id' => $organization->id,
            'created_by_user_id' => $user->id,
            'name' => 'Tower schedule',
            'planned_start_date' => '2026-06-01',
            'planned_end_date' => '2026-06-30',
            'status' => 'active',
        ]);
        $task = ScheduleTask::query()->create([
            'organization_id' => $organization->id,
            'schedule_id' => $schedule->id,
            'created_by_user_id' => $user->id,
            'name' => 'Foundation reinforcement',
            'task_type' => 'task',
            'planned_start_date' => '2026-06-08',
            'planned_end_date' => '2026-06-10',
            'quantity' => 10,
            'planned_work_hours' => 8,
            'estimate_item_id' => $estimateItem->id,
            'progress_percent' => 0,
            'status' => 'not_started',
            'sort_order' => 1,
            'level' => 0,
        ]);
        $lookahead = LookaheadPlan::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'schedule_id' => $schedule->id,
            'created_by_user_id' => $user->id,
            'title' => 'Two week lookahead',
            'start_date' => '2026-06-08',
            'end_date' => '2026-06-21',
            'status' => 'draft',
        ]);
        $lookaheadTask = LookaheadPlanTask::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'schedule_id' => $schedule->id,
            'lookahead_plan_id' => $lookahead->id,
            'schedule_task_id' => $task->id,
            'planned_start_date' => '2026-06-08',
            'planned_end_date' => '2026-06-10',
            'planned_quantity' => 10,
            'planned_work_hours' => 8,
            'readiness_status' => 'pending',
        ]);
        WorkConstraint::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'schedule_id' => $schedule->id,
            'lookahead_plan_task_id' => $lookaheadTask->id,
            'schedule_task_id' => $task->id,
            'created_by_user_id' => $user->id,
            'constraint_type' => 'material_missing',
            'title' => 'Rebar is missing',
            'severity' => 'hard',
            'status' => 'open',
            'due_date' => '2026-06-08',
            'metadata' => [
                'required_material' => [
                    'name' => 'A500 rebar',
                    'quantity' => 2.5,
                    'unit' => 't',
                ],
            ],
        ]);
        $dailyPlan = DailyWorkPlan::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'schedule_id' => $schedule->id,
            'lookahead_plan_id' => $lookahead->id,
            'created_by_user_id' => $user->id,
            'work_date' => '2026-06-08',
            'status' => 'published',
            'published_at' => now(),
        ]);
        $assignment = $dailyPlan->assignments()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'schedule_id' => $schedule->id,
            'lookahead_plan_task_id' => $lookaheadTask->id,
            'schedule_task_id' => $task->id,
            'assigned_user_id' => $user->id,
            'planned_quantity' => 10,
            'planned_work_hours' => 8,
            'status' => 'planned',
        ]);
        ConstructionJournal::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'name' => 'General works journal',
            'journal_number' => 'J-1',
            'start_date' => '2026-06-01',
            'status' => 'active',
            'created_by_user_id' => $user->id,
        ]);

        $listResponse = $this->actingAs($user, 'api_mobile')
            ->getJson("/api/v1/mobile/schedule/daily-plans?project_id={$project->id}");

        $listResponse->assertOk()
            ->assertJsonPath('data.0.id', $dailyPlan->id)
            ->assertJsonPath('data.0.assignments.0.id', $assignment->id)
            ->assertJsonPath('data.0.assignments.0.schedule_task.name', 'Foundation reinforcement')
            ->assertJsonPath('data.0.assignments.0.constraints.0.constraint_type', 'material_missing')
            ->assertJsonPath('data.0.assignments.0.constraints.0.available_actions.0', 'create_linked_action');

        $factResponse = $this->actingAs($user, 'api_mobile')
            ->patchJson("/api/v1/mobile/schedule/daily-plan-assignments/{$assignment->id}/fact", [
                'status' => 'done',
                'completed_quantity' => 10,
                'actual_work_hours' => 8,
                'fact_comment' => 'Completed from mobile',
            ]);

        $factResponse->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.journal_entry_id', fn ($value): bool => $value !== null);
        $journalEntryId = (int) $factResponse->json('data.journal_entry_id');

        $updatedFactResponse = $this->actingAs($user, 'api_mobile')
            ->patchJson("/api/v1/mobile/schedule/daily-plan-assignments/{$assignment->id}/fact", [
                'status' => 'partially_done',
                'completed_quantity' => 6,
                'actual_work_hours' => 7,
                'fact_comment' => 'Updated from mobile before submit',
            ]);

        $updatedFactResponse->assertOk()
            ->assertJsonPath('data.status', 'partially_done')
            ->assertJsonPath('data.journal_entry_id', $journalEntryId);
        $this->assertSame(1, DB::table('construction_journal_entries')
            ->where('schedule_task_id', $task->id)
            ->whereDate('entry_date', '2026-06-08')
            ->count());
        $this->assertDatabaseHas('construction_journal_entries', [
            'id' => $journalEntryId,
            'work_description' => 'Updated from mobile before submit',
        ]);
        $this->assertDatabaseHas('journal_work_volumes', [
            'journal_entry_id' => $journalEntryId,
            'quantity' => 6,
        ]);

        $submitResponse = $this->actingAs($user, 'api_mobile')
            ->postJson("/api/v1/mobile/schedule/daily-plans/{$dailyPlan->id}/submit", [
                'summary_comment' => 'Ready for acceptance',
            ]);

        $submitResponse->assertOk()
            ->assertJsonPath('data.status', 'submitted');
        $this->assertSame('submitted', DB::table('daily_work_plans')->where('id', $dailyPlan->id)->value('status'));
    }

    public function test_foreman_can_convert_daily_plan_constraints_to_linked_site_request_and_quality_defect(): void
    {
        $this->withoutMiddleware();

        $organization = Organization::factory()->verified()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($user->id, ['is_owner' => false, 'is_active' => true]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $project->users()->attach($user->id, ['role' => 'foreman']);

        $schedule = ProjectSchedule::query()->create([
            'project_id' => $project->id,
            'organization_id' => $organization->id,
            'created_by_user_id' => $user->id,
            'name' => 'Tower schedule',
            'planned_start_date' => '2026-06-01',
            'planned_end_date' => '2026-06-30',
            'status' => 'active',
        ]);
        $task = ScheduleTask::query()->create([
            'organization_id' => $organization->id,
            'schedule_id' => $schedule->id,
            'created_by_user_id' => $user->id,
            'name' => 'Foundation reinforcement',
            'task_type' => 'task',
            'planned_start_date' => '2026-06-08',
            'planned_end_date' => '2026-06-10',
            'quantity' => 10,
            'planned_work_hours' => 8,
            'progress_percent' => 0,
            'status' => 'not_started',
            'sort_order' => 1,
            'level' => 0,
        ]);
        $lookahead = LookaheadPlan::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'schedule_id' => $schedule->id,
            'created_by_user_id' => $user->id,
            'title' => 'Two week lookahead',
            'start_date' => '2026-06-08',
            'end_date' => '2026-06-21',
            'status' => 'draft',
        ]);
        $lookaheadTask = LookaheadPlanTask::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'schedule_id' => $schedule->id,
            'lookahead_plan_id' => $lookahead->id,
            'schedule_task_id' => $task->id,
            'planned_start_date' => '2026-06-08',
            'planned_end_date' => '2026-06-10',
            'planned_quantity' => 10,
            'planned_work_hours' => 8,
            'readiness_status' => 'blocked',
        ]);

        $materialConstraint = WorkConstraint::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'schedule_id' => $schedule->id,
            'lookahead_plan_task_id' => $lookaheadTask->id,
            'schedule_task_id' => $task->id,
            'created_by_user_id' => $user->id,
            'constraint_type' => 'material_missing',
            'title' => 'Rebar is missing',
            'description' => 'Need rebar before concrete works',
            'severity' => 'hard',
            'status' => 'open',
            'due_date' => '2026-06-08',
            'metadata' => [
                'required_material' => [
                    'name' => 'A500 rebar',
                    'quantity' => 2.5,
                    'unit' => 't',
                ],
            ],
        ]);
        $qualityConstraint = WorkConstraint::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'schedule_id' => $schedule->id,
            'lookahead_plan_task_id' => $lookaheadTask->id,
            'schedule_task_id' => $task->id,
            'created_by_user_id' => $user->id,
            'constraint_type' => 'quality_blocker',
            'title' => 'Reinforcement layout mismatch',
            'description' => 'Inspection found mismatch with drawings',
            'severity' => 'hard',
            'status' => 'open',
            'due_date' => '2026-06-08',
        ]);
        $safetyConstraint = WorkConstraint::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'schedule_id' => $schedule->id,
            'lookahead_plan_task_id' => $lookaheadTask->id,
            'schedule_task_id' => $task->id,
            'created_by_user_id' => $user->id,
            'constraint_type' => 'safety_permit_missing',
            'title' => 'Hot work permit missing',
            'description' => 'Crew cannot start without a permit',
            'severity' => 'hard',
            'status' => 'open',
            'due_date' => '2026-06-08',
        ]);

        $siteRequestResponse = $this->actingAs($user, 'api_mobile')
            ->postJson("/api/v1/mobile/schedule/work-constraints/{$materialConstraint->id}/linked-action", [
                'comment' => 'Need supply request from field team',
            ]);

        $siteRequestResponse->assertCreated()
            ->assertJsonPath('data.type', 'site_request')
            ->assertJsonPath('data.entity.title', 'Rebar is missing')
            ->assertJsonPath('data.entity.metadata.source.work_constraint_id', $materialConstraint->id);
        $siteRequestId = $siteRequestResponse->json('data.entity.id');
        $this->assertDatabaseHas('site_requests', [
            'id' => $siteRequestId,
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'request_type' => 'material_request',
            'user_id' => $user->id,
        ]);

        $duplicateSiteRequestResponse = $this->actingAs($user, 'api_mobile')
            ->postJson("/api/v1/mobile/schedule/work-constraints/{$materialConstraint->id}/linked-action");

        $duplicateSiteRequestResponse->assertOk()
            ->assertJsonPath('data.type', 'site_request')
            ->assertJsonPath('data.entity.id', $siteRequestId);
        $this->assertSame(1, SiteRequest::query()
            ->where('metadata->source->work_constraint_id', $materialConstraint->id)
            ->count());

        $qualityDefectResponse = $this->actingAs($user, 'api_mobile')
            ->postJson("/api/v1/mobile/schedule/work-constraints/{$qualityConstraint->id}/linked-action", [
                'comment' => 'Open defect for QC team',
            ]);

        $qualityDefectResponse->assertCreated()
            ->assertJsonPath('data.type', 'quality_defect')
            ->assertJsonPath('data.entity.title', 'Reinforcement layout mismatch')
            ->assertJsonPath('data.entity.metadata.source.work_constraint_id', $qualityConstraint->id);
        $qualityDefectId = $qualityDefectResponse->json('data.entity.id');
        $this->assertDatabaseHas('quality_defects', [
            'id' => $qualityDefectId,
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'schedule_task_id' => $task->id,
            'created_by' => $user->id,
        ]);
        $this->assertSame(1, QualityDefect::query()
            ->where('metadata->source->work_constraint_id', $qualityConstraint->id)
            ->count());

        $safetyIncidentResponse = $this->actingAs($user, 'api_mobile')
            ->postJson("/api/v1/mobile/schedule/work-constraints/{$safetyConstraint->id}/linked-action", [
                'comment' => 'No permit found on site',
            ]);

        $safetyIncidentResponse->assertCreated()
            ->assertJsonPath('data.type', 'safety_incident')
            ->assertJsonPath('data.entity.title', 'Hot work permit missing')
            ->assertJsonPath('data.entity.metadata.source.work_constraint_id', $safetyConstraint->id);
        $safetyIncidentId = $safetyIncidentResponse->json('data.entity.id');
        $this->assertDatabaseHas('safety_incidents', [
            'id' => $safetyIncidentId,
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'incident_type' => 'unsafe_condition',
            'status' => 'reported',
            'reported_by_user_id' => $user->id,
        ]);
        $this->assertSame(1, SafetyIncident::query()
            ->where('metadata->source->work_constraint_id', $safetyConstraint->id)
            ->count());
    }
}
