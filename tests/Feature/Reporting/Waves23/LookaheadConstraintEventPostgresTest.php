<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Waves23;

use App\BusinessModules\Features\ScheduleManagement\Models\LookaheadPlan;
use App\BusinessModules\Features\ScheduleManagement\Models\LookaheadPlanTask;
use App\BusinessModules\Features\ScheduleManagement\Models\WorkConstraint;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services\LookaheadReadinessPolicyDefinition;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services\LookaheadReadinessPolicyVersionWriter;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services\WorkConstraintEventRecorder;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Reporting\PostgresProcessRaceHarness;
use Tests\TestCase;

#[Group('postgresql')]
final class LookaheadConstraintEventPostgresTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        self::assertSame(
            'pgsql',
            DB::connection()->getDriverName(),
            'Lookahead constraint event races require isolated PostgreSQL.',
        );
    }

    public function test_two_concurrent_distinct_transitions_receive_consecutive_versions(): void
    {
        $constraint = $this->constraint();
        $constraintId = (int) $constraint->id;
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'lookahead-constraint-event-race-'.bin2hex(random_bytes(6));
        $harness = new PostgresProcessRaceHarness($directory);
        $children = [];

        try {
            foreach (['resolved', 'closed'] as $index => $status) {
                $children[] = $harness->spawn(
                    $index,
                    static function () use ($constraintId, $status): array {
                        $event = app(WorkConstraintEventRecorder::class)->record(
                            WorkConstraint::query()->findOrFail($constraintId),
                            'open',
                            $status,
                            null,
                            new DateTimeImmutable('2026-07-30T12:00:00+00:00'),
                        );

                        return [
                            'event_id' => (int) $event->id,
                            'event_version' => (int) $event->event_version,
                            'status' => (string) $event->to_status,
                        ];
                    },
                );
            }
            $harness->release(0);
            $harness->release(1);
            $harness->waitForChildren($children);

            self::assertSame(
                [1, 2],
                DB::table('work_constraint_transition_events')
                    ->where('constraint_id', $constraintId)
                    ->orderBy('event_version')
                    ->pluck('event_version')
                    ->map('intval')
                    ->all(),
            );
            self::assertSame(
                ['closed', 'resolved'],
                DB::table('work_constraint_transition_events')
                    ->where('constraint_id', $constraintId)
                    ->pluck('to_status')
                    ->sort()
                    ->values()
                    ->all(),
            );
        } finally {
            $harness->terminateAndReap($children);
            $harness->cleanup();
        }
    }

    public function test_policy_task_state_and_constraint_event_histories_are_append_only(): void
    {
        $constraint = $this->constraint();
        $event = app(WorkConstraintEventRecorder::class)->record(
            $constraint,
            null,
            'open',
            null,
            new DateTimeImmutable('2026-07-30T12:00:00+00:00'),
        );
        $policy = app(LookaheadReadinessPolicyVersionWriter::class)->publish(
            LookaheadReadinessPolicyDefinition::default(
                (int) $constraint->organization_id,
                new DateTimeImmutable('2026-07-30T00:00:00+00:00'),
            ),
        );
        $taskStateId = DB::table('schedule_task_state_versions')
            ->where('task_id', $constraint->schedule_task_id)
            ->value('id');
        self::assertIsNumeric($taskStateId);

        $this->assertMutationRejected(
            static fn (): int => DB::table('lookahead_readiness_policy_versions')
                ->where('id', $policy->policyId)
                ->update(['horizon_days' => 31]),
        );
        $this->assertMutationRejected(
            static fn (): int => DB::table('schedule_task_state_versions')
                ->where('id', (int) $taskStateId)
                ->update(['status' => 'completed']),
        );
        $this->assertMutationRejected(
            static fn (): int => DB::table('work_constraint_transition_events')
                ->where('id', $event->id)
                ->delete(),
        );
    }

    private function constraint(): WorkConstraint
    {
        $organization = Organization::factory()->verified()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $schedule = ProjectSchedule::query()->create([
            'project_id' => $project->id,
            'organization_id' => $organization->id,
            'created_by_user_id' => $user->id,
            'name' => 'Race schedule',
            'planned_start_date' => '2026-07-01',
            'planned_end_date' => '2026-08-31',
            'status' => 'active',
        ]);
        $task = ScheduleTask::query()->create([
            'organization_id' => $organization->id,
            'schedule_id' => $schedule->id,
            'created_by_user_id' => $user->id,
            'name' => 'Race task',
            'task_type' => 'task',
            'planned_start_date' => '2026-08-01',
            'planned_end_date' => '2026-08-02',
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
            'title' => 'Race lookahead',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-14',
            'status' => 'draft',
        ]);
        $lookaheadTask = LookaheadPlanTask::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'schedule_id' => $schedule->id,
            'lookahead_plan_id' => $lookahead->id,
            'schedule_task_id' => $task->id,
            'planned_start_date' => '2026-08-01',
            'planned_end_date' => '2026-08-02',
            'readiness_status' => 'pending',
        ]);

        return WorkConstraint::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'schedule_id' => $schedule->id,
            'lookahead_plan_task_id' => $lookaheadTask->id,
            'schedule_task_id' => $task->id,
            'created_by_user_id' => $user->id,
            'constraint_type' => 'rfi',
            'title' => 'Race constraint',
            'severity' => 'hard',
            'status' => 'open',
        ]);
    }

    private function assertMutationRejected(callable $mutation): void
    {
        try {
            DB::transaction($mutation);
            self::fail('Expected append-only history mutation to be rejected.');
        } catch (QueryException $exception) {
            self::assertSame('55000', $exception->getCode());
        }
    }
}
