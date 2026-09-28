<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\ScheduleManagement\Models\DailyWorkPlan;
use App\BusinessModules\Features\ScheduleManagement\Models\LookaheadPlan;
use App\BusinessModules\Features\ScheduleManagement\Models\LookaheadPlanTask;
use App\Enums\EstimatePositionItemType;
use App\Models\ConstructionJournal;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\JournalWorkVolume;
use App\Models\MeasurementUnit;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MobileProjectRoleTestContext;
use Tests\TestCase;

final class MobileDailyPlanSubmitPreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_daily_plan_preflight_reports_hard_blockers_and_preserves_fact_units(): void
    {
        $context = MobileProjectRoleTestContext::create('foreman');
        $context->activatePackages(['working-entry']);

        $unitCount = MeasurementUnit::query()->firstOrCreate(
            [
                'organization_id' => $context->organization->id,
                'short_name' => 'шт',
            ],
            [
                'name' => 'Штука',
                'type' => 'work',
            ],
        );
        $unitVolume = MeasurementUnit::query()->firstOrCreate(
            [
                'organization_id' => $context->organization->id,
                'short_name' => 'м³',
            ],
            [
                'name' => 'Кубический метр',
                'type' => 'work',
            ],
        );

        $estimate = Estimate::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $context->project->id,
            'number' => 'EST-MOBILE-PREFLIGHT',
            'name' => 'Mobile preflight estimate',
            'status' => 'approved',
            'estimate_date' => now()->toDateString(),
            'total_amount' => 1000,
        ]);
        $estimateItem = EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'position_number' => '1',
            'item_type' => EstimatePositionItemType::WORK->value,
            'name' => 'Concrete placement',
            'measurement_unit_id' => $unitVolume->id,
            'quantity' => 10,
            'quantity_total' => 10,
            'unit_price' => 100,
            'total_amount' => 1000,
        ]);

        $schedule = ProjectSchedule::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $context->project->id,
            'created_by_user_id' => $context->user->id,
            'estimate_id' => $estimate->id,
            'name' => 'Mobile preflight schedule',
            'planned_start_date' => now()->toDateString(),
            'planned_end_date' => now()->addDays(20)->toDateString(),
            'status' => 'active',
        ]);
        $lookahead = LookaheadPlan::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $context->project->id,
            'schedule_id' => $schedule->id,
            'created_by_user_id' => $context->user->id,
            'title' => 'Mobile preflight plan',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(13)->toDateString(),
            'status' => 'published',
        ]);
        $journal = ConstructionJournal::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $context->project->id,
            'name' => 'Mobile preflight journal',
            'journal_number' => 'J-MOBILE-PREFLIGHT',
            'start_date' => now()->subDay()->toDateString(),
            'status' => 'active',
            'created_by_user_id' => $context->user->id,
        ]);

        $unlinkedTask = $this->createScheduleTask($context, $schedule, 'Manual task', $unitCount);
        $linkedTask = $this->createScheduleTask($context, $schedule, 'Estimate task', $unitVolume, $estimateItem);
        $noUnitTask = $this->createScheduleTask($context, $schedule, 'Task without unit', null);
        $unlinkedPlan = $this->createDailyPlan($context, $schedule, $lookahead, $unlinkedTask, 'in_progress', 1);
        $linkedPlan = $this->createDailyPlan($context, $schedule, $lookahead, $linkedTask, 'published', 2);
        $publishedPlan = $this->createDailyPlan($context, $schedule, $lookahead, $noUnitTask, 'published', 3);
        $returnedFactPlan = $this->createDailyPlan($context, $schedule, $lookahead, $unlinkedTask, 'returned', 4);
        $returnedSubmitPlan = $this->createDailyPlan($context, $schedule, $lookahead, $noUnitTask, 'returned', 5);

        $unlinkedFact = $this->withHeaders($context->headers())
            ->patchJson("/api/v1/mobile/schedule/daily-plan-assignments/{$unlinkedPlan['assignment_id']}/fact", [
                'status' => 'done',
                'completed_quantity' => 0.001,
                'actual_work_hours' => 0.01,
                'fact_comment' => 'Manual task fact',
            ]);
        $unlinkedFact->assertOk()->assertJsonPath('data.measurement_unit', 'шт');

        $linkedFact = $this->withHeaders($context->headers())
            ->patchJson("/api/v1/mobile/schedule/daily-plan-assignments/{$linkedPlan['assignment_id']}/fact", [
                'status' => 'done',
                'completed_quantity' => 0.001,
                'actual_work_hours' => 0.01,
                'fact_comment' => 'Estimate task fact',
            ]);
        $linkedFact->assertOk()->assertJsonPath('data.measurement_unit', 'м³');

        $linkedEntryId = (int) $linkedFact->json('data.journal_entry_id');
        DB::table('construction_journal_entries')
            ->where('id', $linkedEntryId)
            ->update(['schedule_task_id' => null]);
        JournalWorkVolume::query()->create([
            'journal_entry_id' => $linkedEntryId,
            'estimate_item_id' => $estimateItem->id,
            'quantity' => 0.25,
            'measurement_unit_id' => $unitVolume->id,
            'notes' => 'Legacy linked estimate volume',
        ]);

        $captureResolverQueries = true;
        $resolverQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$captureResolverQueries, &$resolverQueries): void {
            $sql = strtolower($query->sql);
            if ($captureResolverQueries && str_contains($sql, 'schedule_tasks') && str_contains($sql, 'estimate_item_id')) {
                $resolverQueries[] = $query->sql;
            }
        });
        $list = $this->withHeaders($context->headers())
            ->getJson("/api/v1/mobile/schedule/daily-plans?project_id={$context->project->id}")
            ->assertOk()
            ->json('data');
        $captureResolverQueries = false;
        $this->assertSame([], $resolverQueries, 'Mobile preflight must not resolve overridable schedule blockers per journal volume.');

        $unlinkedPayload = collect($list)->firstWhere('id', $unlinkedPlan['daily_plan_id']);
        $this->assertNotNull($unlinkedPayload);
        $this->assertNotContains('submit', collect($unlinkedPayload['available_actions'])->pluck('action')->all());
        $this->assertContains('record_fact', collect($unlinkedPayload['available_actions'])->pluck('action')->all());
        $this->assertSame('шт', $unlinkedPayload['assignments'][0]['measurement_unit']);
        $this->assertSame('missing_estimate_item', $unlinkedPayload['submit_blockers'][0]['code']);
        $this->assertSame(
            trans_message('workflow.blockers.missing_estimate_item'),
            $unlinkedPayload['submit_blockers'][0]['message'],
        );

        $linkedPayload = collect($list)->firstWhere('id', $linkedPlan['daily_plan_id']);
        $this->assertNotNull($linkedPayload);
        $this->assertContains('submit', collect($linkedPayload['available_actions'])->pluck('action')->all());
        $this->assertSame([], $linkedPayload['submit_blockers']);
        $this->assertSame('м³', $linkedPayload['assignments'][0]['measurement_unit']);

        $publishedPayload = collect($list)->firstWhere('id', $publishedPlan['daily_plan_id']);
        $this->assertNotNull($publishedPayload);
        $this->assertContains('submit', collect($publishedPayload['available_actions'])->pluck('action')->all());
        $this->assertSame([], $publishedPayload['submit_blockers']);
        $this->assertNull($publishedPayload['assignments'][0]['measurement_unit']);

        $returnedFactPayload = collect($list)->firstWhere('id', $returnedFactPlan['daily_plan_id']);
        $this->assertNotNull($returnedFactPayload);
        $this->assertSame('returned', $returnedFactPayload['status']);
        $this->assertContains('record_fact', collect($returnedFactPayload['available_actions'])->pluck('action')->all());
        $this->assertContains('submit', collect($returnedFactPayload['available_actions'])->pluck('action')->all());
        $this->assertSame([], $returnedFactPayload['submit_blockers']);

        $returnedSubmitPayload = collect($list)->firstWhere('id', $returnedSubmitPlan['daily_plan_id']);
        $this->assertNotNull($returnedSubmitPayload);
        $this->assertSame('returned', $returnedSubmitPayload['status']);
        $this->assertContains('submit', collect($returnedSubmitPayload['available_actions'])->pluck('action')->all());
        $this->assertSame([], $returnedSubmitPayload['submit_blockers']);

        $this->withHeaders($context->headers())
            ->patchJson("/api/v1/mobile/schedule/daily-plan-assignments/{$returnedFactPlan['assignment_id']}/fact", [
                'status' => 'done',
                'completed_quantity' => 0.001,
                'actual_work_hours' => 0.01,
                'fact_comment' => 'Fact for returned plan',
            ])
            ->assertOk();
        $this->assertDatabaseHas('daily_work_plans', [
            'id' => $returnedFactPlan['daily_plan_id'],
            'status' => 'in_progress',
        ]);

        $this->withHeaders($context->headers())
            ->postJson("/api/v1/mobile/schedule/daily-plans/{$returnedSubmitPlan['daily_plan_id']}/submit", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $blockedSubmit = $this->withHeaders($context->headers())
            ->postJson("/api/v1/mobile/schedule/daily-plans/{$unlinkedPlan['daily_plan_id']}/submit", [
                'summary_comment' => 'Should roll back on hard blocker',
            ]);
        $blockedSubmit->assertStatus(400)
            ->assertJsonPath('message', trans_message('construction_journal.errors.submit_validation_prefix').': '.trans_message('workflow.blockers.missing_estimate_item'));

        $this->assertDatabaseHas('daily_work_plans', [
            'id' => $unlinkedPlan['daily_plan_id'],
            'status' => 'in_progress',
            'submitted_at' => null,
            'summary_comment' => null,
        ]);
        $unlinkedEntryId = $unlinkedFact->json('data.journal_entry_id');
        $this->assertDatabaseHas('construction_journal_entries', [
            'id' => $unlinkedEntryId,
            'journal_id' => $journal->id,
            'status' => 'draft',
        ]);
        $this->assertDatabaseHas('journal_work_volumes', [
            'journal_entry_id' => $unlinkedEntryId,
            'estimate_item_id' => null,
            'quantity' => 0.001,
        ]);
        $this->assertSame(0, DB::table('journal_entry_approval_events')
            ->where('journal_entry_id', $unlinkedEntryId)
            ->where('event', 'submitted')
            ->count());

        $this->withHeaders($context->headers())
            ->postJson("/api/v1/mobile/schedule/daily-plans/{$linkedPlan['daily_plan_id']}/submit", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');
    }

    /** @return array{daily_plan_id: int, assignment_id: int} */
    private function createDailyPlan(
        MobileProjectRoleTestContext $context,
        ProjectSchedule $schedule,
        LookaheadPlan $lookahead,
        ScheduleTask $task,
        string $status,
        int $dayOffset,
    ): array {
        $workDate = now()->addDays($dayOffset)->toDateString();
        $planTask = LookaheadPlanTask::query()->firstOrCreate(
            [
                'lookahead_plan_id' => $lookahead->id,
                'schedule_task_id' => $task->id,
            ],
            [
                'organization_id' => $context->organization->id,
                'project_id' => $context->project->id,
                'schedule_id' => $schedule->id,
                'planned_start_date' => $workDate,
                'planned_end_date' => $workDate,
                'planned_quantity' => 1,
                'planned_work_hours' => 1,
                'readiness_status' => 'ready',
            ],
        );
        $dailyPlan = DailyWorkPlan::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $context->project->id,
            'schedule_id' => $schedule->id,
            'lookahead_plan_id' => $lookahead->id,
            'created_by_user_id' => $context->user->id,
            'work_date' => $workDate,
            'status' => $status,
            'published_at' => now(),
        ]);
        $assignment = $dailyPlan->assignments()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $context->project->id,
            'schedule_id' => $schedule->id,
            'lookahead_plan_task_id' => $planTask->id,
            'schedule_task_id' => $task->id,
            'assigned_user_id' => $context->user->id,
            'planned_quantity' => 1,
            'planned_work_hours' => 1,
            'status' => 'planned',
        ]);

        return ['daily_plan_id' => (int) $dailyPlan->id, 'assignment_id' => (int) $assignment->id];
    }

    private function createScheduleTask(
        MobileProjectRoleTestContext $context,
        ProjectSchedule $schedule,
        string $name,
        ?MeasurementUnit $unit,
        ?EstimateItem $estimateItem = null,
    ): ScheduleTask {
        return ScheduleTask::query()->create([
            'organization_id' => $context->organization->id,
            'schedule_id' => $schedule->id,
            'created_by_user_id' => $context->user->id,
            'estimate_item_id' => $estimateItem?->id,
            'measurement_unit_id' => $unit?->id,
            'name' => $name,
            'task_type' => 'task',
            'planned_start_date' => now()->toDateString(),
            'planned_end_date' => now()->addDays(10)->toDateString(),
            'quantity' => 1,
            'planned_work_hours' => 1,
            'progress_percent' => 0,
            'status' => 'not_started',
            'sort_order' => 1,
        ]);
    }
}
