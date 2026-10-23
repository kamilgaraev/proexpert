<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\ScheduleManagement\Models\ProjectEvent;
use App\BusinessModules\Features\ScheduleManagement\Services\ProjectEventService;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\AdminApiTestContext;
use Tests\Support\EnablesImmutableAuditWriter;
use Tests\TestCase;

class ProjectEventRecurrenceScopeTest extends TestCase
{
    use RefreshDatabase, EnablesImmutableAuditWriter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableImmutableAuditWriter();
    }

    public function test_single_root_edit_keeps_the_template_child_ids_and_terminal_history(): void
    {
        $root = $this->series();
        $children = $this->members($root)->slice(1)->values();
        $terminal = $children->last();
        $terminal->update(['status' => 'completed', 'notes' => 'Completed evidence', 'reminder_sent' => true]);
        $this->change($root, ['title' => 'Only root', 'event_date' => '2026-11-01'], 'single');
        $this->assertSame('Only root', $root->fresh()->title);
        $this->assertSame($children->pluck('id')->all(), $root->recurringChildren()->orderBy('series_position')->pluck('id')->all());
        $this->assertSame('Teaching series', $children->first()->fresh()->title);
        $this->change($children->first()->fresh(), ['recurrence_until' => '2026-10-25'], 'future');
        $new = $this->members($root)->last();
        $this->assertSame('Teaching series', $new->title);
        $this->assertSame('2026-10-25', $new->event_date->toDateString());
        $this->assertSame('completed', $terminal->fresh()->status);
        $this->assertSame('Completed evidence', $terminal->fresh()->notes);
        $this->assertTrue($terminal->fresh()->reminder_sent);
        $this->assertSame(3, DB::table('immutable_audit_events')->where('domain', 'schedule_management')->count());
    }

    public function test_future_child_edit_uses_stable_positions_and_preserves_other_overrides(): void
    {
        $root = $this->series();
        [$first, $pivot, $manual, $terminal] = $this->members($root)->all();
        $this->change($manual, ['title' => 'Manual title', 'event_date' => '2026-11-07', 'notes' => 'Keep note'], 'single');
        $terminal->update(['status' => 'completed']);
        $response = $this->change($pivot->fresh(), [
            'title' => 'Future title', 'event_date' => '2026-10-28',
            'recurrence_pattern' => 'weekly', 'recurrence_until' => '2026-11-18',
        ], 'future');
        $this->assertSame('2026-10-20', $first->fresh()->event_date->toDateString());
        $this->assertSame('2026-10-28', $pivot->fresh()->event_date->toDateString());
        $this->assertSame('Future title', $pivot->fresh()->title);
        $this->assertSame('2026-11-07', $manual->fresh()->event_date->toDateString());
        $this->assertSame('Manual title', $manual->fresh()->title);
        $this->assertSame('Keep note', $manual->fresh()->notes);
        $this->assertSame('2026-10-23', $terminal->fresh()->event_date->toDateString());
        $this->assertSame('2026-11-18', $this->members($root)->last()->event_date->toDateString());
        $this->assertSame(1, $response->recurrence_operation['preserved']);
        $this->assertSame(1, $response->recurrence_operation['created']);
    }

    public function test_shortening_and_extending_reuses_suppressed_ids_and_all_readers_exclude_them(): void
    {
        $root = $this->series(['is_blocking' => true, 'reminder_before_hours' => 24]);
        $original = $this->members($root)->pluck('id')->all();
        $this->change($root, ['recurrence_until' => '2026-10-21'], 'future');
        $suppressed = $this->members($root)->last();
        $this->assertTrue($suppressed->recurrence_suppressed);
        $service = app(ProjectEventService::class);
        $from = \Carbon\Carbon::parse('2026-10-20');
        $until = \Carbon\Carbon::parse('2026-10-31');
        $this->assertSame(2, $service->getCalendarEvents($root->project_id, $from, $until)->count());
        $this->assertSame(2, $service->getPaginatedEvents($root->project_id)->total());
        $this->assertSame(2, $service->getEventsStatistics($root->project_id, $from, $until)['total']);
        $this->assertSame(2, $service->getBlockingEvents($root->project_id, $from, $until)->count());
        $this->assertFalse($service->checkEventConflicts($suppressed)['has_conflicts']);
        $this->assertSame(0, ProjectEvent::query()->needsReminder()->whereKey($suppressed->id)->count());
        $this->assertNotNull(ProjectEvent::find($suppressed->id));
        $this->change($root->fresh(), ['recurrence_until' => '2026-10-23'], 'future');
        $this->assertSame($original, $this->members($root)->pluck('id')->all());
        $this->assertSame(0, $this->members($root)->where('recurrence_suppressed', true)->count());
    }

    public function test_retry_is_idempotent_before_revision_check_and_reused_payload_is_rejected(): void
    {
        $root = $this->series();
        $patch = ['title' => 'Retry once', 'recurrence_scope' => 'future', 'operation_id' => (string) Str::uuid(), 'expected_revision' => 0];
        $service = app(ProjectEventService::class);
        $first = $service->updateEvent($root, $patch)->toArray();
        $this->change($root->fresh(), ['description' => 'A later independent edit'], 'single');
        $count = DB::table('immutable_audit_events')->where('domain', 'schedule_management')->count();
        $this->assertEquals($first, $service->updateEvent($root->fresh(), $patch)->toArray());
        $this->assertSame($count, DB::table('immutable_audit_events')->where('domain', 'schedule_management')->count());
        $this->assertSame(4, $this->members($root)->count());
        try {
            $service->updateEvent($root->fresh(), array_replace($patch, ['title' => 'Different request']));
            $this->fail('Reused operation accepted');
        } catch (ConflictHttpException) {
            $this->assertSame('Retry once', $root->fresh()->title);
        }
        $this->expectException(ConflictHttpException::class);
        $service->updateEvent($root->fresh(), array_replace($patch, ['operation_id' => (string) Str::uuid()]));
    }

    public function test_monthly_intervals_keep_month_end_seconds_and_multiday_duration(): void
    {
        $root = $this->series([
            'event_date' => '2028-01-31', 'end_date' => '2028-02-01', 'end_time' => '10:45:15',
            'duration_minutes' => null, 'recurrence_pattern' => 'monthly', 'recurrence_until' => '2028-03-31',
        ]);
        $members = $this->members($root);
        $this->assertSame(['2028-01-31', '2028-02-29', '2028-03-31'], $members->map(fn ($event) => $event->event_date->toDateString())->all());
        $this->assertSame('2028-03-01', $members[1]->end_date->toDateString());
        $this->assertSame('10:45:15', $members[1]->end_time);
        $this->assertSame('09:15:30', $members[1]->event_time);
        $this->assertNull($members[1]->duration_minutes);
    }

    public function test_legacy_unknown_end_and_overrides_are_not_guessed_or_overwritten(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $root = ProjectEvent::create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'title' => 'Legacy root', 'event_type' => 'meeting', 'event_date' => '2026-10-20',
            'is_recurring' => true, 'recurrence_pattern' => 'daily',
        ])->fresh();
        $child = $root->replicate()->fill(['is_recurring' => false, 'recurring_parent_id' => $root->id, 'event_date' => '2026-11-03', 'title' => 'Unknown manual value']);
        $child->save();
        $this->assertNull($root->recurrence_until);
        $this->assertFalse($child->fresh()->recurrence_context['end_known']);
        $response = $this->change($root, ['title' => 'Future explicit', 'recurrence_until' => '2026-10-23'], 'future');
        $this->assertSame('Unknown manual value', $child->fresh()->title);
        $this->assertSame('2026-11-03', $child->fresh()->event_date->toDateString());
        $this->assertSame(1, $response->recurrence_operation['preserved']);
        $this->assertSame('2026-10-23', $root->fresh()->recurrence_until);
        $this->assertSame('Future explicit', $this->members($root)->last()->title);
    }

    public function test_writer_failure_rolls_back_occurrences_segments_revision_and_operation(): void
    {
        $root = $this->series();
        $before = $this->members($root)->map->getAttributes()->all();
        $segments = DB::table('project_event_series_segments')->count();
        config(['legal_archive.audit_writer_secret' => '']);
        $this->app->forgetInstance(\App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRecorder::class);
        $service = new ProjectEventService(new \App\BusinessModules\Features\ScheduleManagement\Services\ProjectEventRecurrenceService(
            $this->app->make(\App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRecorder::class),
        ));
        try {
            $this->change($root, ['title' => 'Must roll back', 'recurrence_until' => '2026-10-27'], 'future', $service);
            $this->fail('Audit writer failure was ignored');
        } catch (\DomainException) {
            $this->assertSame($before, $this->members($root)->map->getAttributes()->all());
            $this->assertSame($segments, DB::table('project_event_series_segments')->count());
            $this->assertSame(0, DB::table('project_event_series_operations')->count());
            $this->assertSame(0, $root->fresh()->recurrence_context['revision']);
        }
    }

    public function test_rule_change_in_single_scope_and_excessive_generation_are_atomic(): void
    {
        $root = $this->series();
        foreach ([['recurrence_pattern' => 'weekly'], ['recurrence_until' => '2036-01-01']] as $index => $patch) {
            try {
                $this->change($root->fresh(), $patch, $index === 0 ? 'single' : 'future');
                $this->fail('Invalid operation accepted');
            } catch (ValidationException) {
                $this->assertSame(4, $this->members($root)->count());
                $this->assertSame(0, $root->fresh()->recurrence_context['revision']);
                $this->assertSame(1, DB::table('project_event_series_segments')->count());
            }
        }
    }

    public function test_disabling_recurrence_keeps_terminal_and_manual_exceptions_visible(): void
    {
        $root = $this->series();
        [$root, $ordinary, $manual, $completed] = $this->members($root)->all();
        $this->change($manual, ['notes' => 'Manual exception'], 'single');
        $completed->update(['status' => 'completed']);
        $this->change($root->fresh(), ['is_recurring' => false], 'future');
        $this->assertTrue($ordinary->fresh()->recurrence_suppressed);
        $this->assertFalse($manual->fresh()->recurrence_suppressed);
        $this->assertFalse($completed->fresh()->recurrence_suppressed);
        $this->assertFalse($root->fresh()->recurrence_context['enabled']);
        $this->assertNotNull($completed->fresh()->recurrence_context);
    }

    public function test_foreign_users_and_schedule_links_are_rejected_without_partial_changes(): void
    {
        $root = $this->series();
        $foreignUser = \App\Models\User::factory()->create();
        $foreignProject = Project::factory()->create();
        $schedule = \App\Models\ProjectSchedule::create([
            'organization_id' => $foreignProject->organization_id, 'project_id' => $foreignProject->id,
            'created_by_user_id' => $foreignUser->id,
            'name' => 'Foreign schedule', 'planned_start_date' => '2026-10-20', 'planned_end_date' => '2026-10-30',
            'status' => 'draft',
        ]);
        foreach ([['participants' => [$foreignUser->id]], ['responsible_users' => [$foreignUser->id]], ['schedule_id' => $schedule->id]] as $patch) {
            try {
                $this->change($root->fresh(), ['title' => 'Must not persist', ...$patch], 'future');
                $this->fail('Foreign reference accepted');
            } catch (ValidationException) {
                $this->assertSame('Teaching series', $root->fresh()->title);
                $this->assertSame(0, $root->fresh()->recurrence_context['revision']);
                $this->assertSame(4, $this->members($root)->count());
            }
        }
    }

    public function test_soft_deleted_occurrence_is_not_recreated_by_an_extension(): void
    {
        $root = $this->series();
        $deleted = $this->members($root)[2];
        app(ProjectEventService::class)->deleteEvent($deleted);
        $this->change($root->fresh(), ['recurrence_until' => '2026-10-25'], 'future');
        $this->assertSoftDeleted('project_events', ['id' => $deleted->id]);
        $this->assertSame(1, ProjectEvent::withTrashed()->where('series_id', $root->series_id)->where('series_position', 2)->count());
        $this->assertSame(5, $this->members($root)->count());
    }

    public function test_earlier_partial_changes_preserve_later_rules_and_untouched_fields(): void
    {
        $root = $this->series();
        $late = $this->members($root)[2];
        $this->change($late, ['title' => 'Later title', 'recurrence_pattern' => 'weekly', 'recurrence_until' => '2026-11-12'], 'future');
        $this->change($root->fresh(), ['description' => 'Earlier description'], 'future');
        $later = $this->members($root)[3];
        $this->assertSame('Later title', $later->title);
        $this->assertSame('Earlier description', $later->description);
        $this->assertSame('2026-10-29', $later->event_date->toDateString());
        $this->assertSame('weekly', $later->recurrence_context['pattern']);
        $this->assertSame('2026-11-12', $later->recurrence_context['until']);
        $this->change($root->fresh(), ['event_date' => '2026-10-21'], 'future');
        $this->assertSame('2026-10-30', $later->fresh()->event_date->toDateString());
        $this->assertSame('weekly', $later->fresh()->recurrence_context['pattern']);
        $this->change($root->fresh(), ['recurrence_pattern' => 'monthly', 'recurrence_until' => '2026-12-21'], 'future');
        $this->assertSame('2026-12-21', $late->fresh()->event_date->toDateString());
        $this->assertSame('Later title', $late->fresh()->title);
    }

    public function test_generation_continues_past_a_stopped_range_when_a_later_range_is_enabled(): void
    {
        $root = $this->series();
        $members = $this->members($root);
        $this->change($members[1], ['is_recurring' => false], 'future');
        $this->change($members[3]->fresh(), ['is_recurring' => true, 'recurrence_until' => '2026-10-26'], 'future');
        $this->change($root->fresh(), ['description' => 'Preserve the later restart'], 'future');
        $this->assertTrue($members[2]->fresh()->recurrence_suppressed);
        $this->assertFalse($members[3]->fresh()->recurrence_suppressed);
        $this->assertSame('2026-10-26', $this->members($root)->last()->event_date->toDateString());
        $this->assertSame('Preserve the later restart', $this->members($root)->last()->description);
    }

    public function test_date_shift_uses_the_plan_position_when_the_pivot_has_a_manual_move(): void
    {
        $root = $this->series();
        $pivot = $this->members($root)[1];
        $next = $this->members($root)[2];
        $this->change($pivot, ['event_date' => '2026-11-07'], 'single');
        $this->change($pivot->fresh(), ['event_date' => '2026-11-08', 'recurrence_until' => '2026-11-10'], 'future');
        $this->assertSame('2026-11-08', $pivot->fresh()->event_date->toDateString());
        $this->assertSame('2026-11-09', $next->fresh()->event_date->toDateString());
    }

    public function test_generation_size_failure_rolls_back_the_whole_new_series(): void
    {
        try {
            $this->series(['description' => str_repeat('x', 700000)]);
            $this->fail('Oversized materialization accepted');
        } catch (ValidationException) {
            $this->assertSame(0, ProjectEvent::query()->count());
            $this->assertSame(0, DB::table('project_event_series')->count());
        }
    }

    private function series(array $data = []): ProjectEvent
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);

        return app(ProjectEventService::class)->createEvent($project->id, $context->organization->id, array_replace([
            'event_type' => 'meeting', 'title' => 'Teaching series', 'event_date' => '2026-10-20',
            'event_time' => '09:15:30', 'is_recurring' => true, 'recurrence_pattern' => 'daily',
            'recurrence_until' => '2026-10-23',
        ], $data))->fresh();
    }

    private function members(ProjectEvent $root): \Illuminate\Database\Eloquent\Collection
    {
        return ProjectEvent::query()->where('series_id', $root->fresh()->series_id)->orderBy('series_position')->get();
    }

    private function change(ProjectEvent $event, array $patch, string $scope, ?ProjectEventService $service = null): ProjectEvent
    {
        return ($service ?? app(ProjectEventService::class))->updateEvent($event, [
            ...$patch, 'recurrence_scope' => $scope, 'expected_revision' => $event->recurrence_context['revision'] ?? 0,
            'operation_id' => (string) Str::uuid(),
        ]);
    }
}
