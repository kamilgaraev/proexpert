<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Features\ScheduleManagement;

use App\BusinessModules\Features\ScheduleManagement\Models\ProjectEvent;
use App\BusinessModules\Features\ScheduleManagement\Services\ProjectEventService;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ProjectEventDateRangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_end_migration_rolls_back_unknown_durations_without_failing_and_keeps_the_default(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::withoutEvents(static fn () => Project::factory()->create(['organization_id' => $context->organization->id]));
        $attributes = [
            'organization_id' => $context->organization->id, 'created_by_user_id' => $context->user->id, 'project_id' => $project->id,
            'event_type' => 'meeting', 'title' => 'Migration fixture', 'event_date' => '2026-10-24',
        ];
        $unknown = ProjectEvent::query()->create([...$attributes, 'duration_minutes' => null]);
        $migration = require app_path('BusinessModules/Features/ScheduleManagement/migrations/2026_10_23_030000_add_exact_end_time_to_project_events.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('project_events', 'end_time'));
        $this->assertSame(0, $unknown->fresh()->duration_minutes);
        $migration->up();
        $this->assertTrue(Schema::hasColumn('project_events', 'end_time'));
        $native = ProjectEvent::query()->create($attributes)->fresh();
        $this->assertSame(60, $native->duration_minutes);
        $native->update(['duration_minutes' => null]);
        $this->assertNull($native->fresh()->duration_minutes);
    }

    public function test_a_midday_event_finds_all_day_and_multiday_blockers_on_its_first_day(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::withoutEvents(static fn () => Project::factory()->create([
            'organization_id' => $context->organization->id,
        ]));
        $attributes = [
            'organization_id' => $context->organization->id,
            'created_by_user_id' => $context->user->id,
            'project_id' => $project->id,
            'event_type' => 'meeting', 'title' => 'All-day candidate fixture',
            'event_date' => '2026-10-24', 'event_time' => null, 'duration_minutes' => 60,
            'is_all_day' => true, 'is_blocking' => true, 'priority' => 'normal', 'status' => 'scheduled',
        ];
        $allDay = ProjectEvent::query()->create($attributes);
        $multiday = ProjectEvent::query()->create(array_replace($attributes, [
            'event_date' => '2026-10-23', 'end_date' => '2026-10-24',
        ]));
        $meeting = ProjectEvent::query()->create(array_replace($attributes, [
            'event_time' => '12:00', 'is_all_day' => false, 'is_blocking' => false,
        ]));

        $conflicts = (new ProjectEventService())->checkEventConflicts($meeting);

        $this->assertTrue($conflicts['has_conflicts']);
        $this->assertEqualsCanonicalizing([$allDay->id, $multiday->id], $conflicts['conflicts']->pluck('id')->all());
    }

    public function test_conflicts_compare_exact_half_open_intervals_and_all_day_boundaries(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::withoutEvents(static fn () => Project::factory()->create([
            'organization_id' => $context->organization->id,
        ]));
        $attributes = [
            'organization_id' => $context->organization->id,
            'created_by_user_id' => $context->user->id,
            'project_id' => $project->id,
            'event_type' => 'meeting', 'title' => 'Exact conflict fixture',
            'event_date' => '2026-10-24', 'event_time' => '23:30', 'duration_minutes' => 120,
            'is_all_day' => false, 'is_blocking' => true, 'priority' => 'normal', 'status' => 'scheduled',
        ];
        $night = ProjectEvent::query()->create($attributes);
        $next = ProjectEvent::query()->create(array_replace($attributes, [
            'event_date' => '2026-10-25', 'event_time' => '18:00', 'duration_minutes' => 60,
        ]));
        $service = new ProjectEventService();

        $this->assertFalse($service->checkEventConflicts($night)['has_conflicts']);
        $this->assertFalse($service->checkEventConflicts($next)['has_conflicts']);

        $next->update(['event_time' => '01:30']);
        $this->assertFalse($service->checkEventConflicts($night)['has_conflicts']);
        $this->assertFalse($service->checkEventConflicts($next)['has_conflicts']);

        $next->update(['event_time' => '01:29']);
        $this->assertTrue($service->checkEventConflicts($night)['has_conflicts']);
        $this->assertTrue($service->checkEventConflicts($next)['has_conflicts']);

        $next->update(['is_all_day' => true, 'event_time' => '18:00']);
        $this->assertTrue($service->checkEventConflicts($night)['has_conflicts']);
        $this->assertTrue($service->checkEventConflicts($next)['has_conflicts']);

        $night->update(['end_date' => '2026-10-24']);
        $this->assertFalse($service->checkEventConflicts($night)['has_conflicts']);
        $this->assertFalse($service->checkEventConflicts($next)['has_conflicts']);
    }

    public function test_conflicts_cover_both_directions_of_an_overnight_event_and_exclude_its_midnight_end(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::withoutEvents(static fn () => Project::factory()->create([
            'organization_id' => $context->organization->id,
        ]));
        $attributes = [
            'organization_id' => $context->organization->id,
            'created_by_user_id' => $context->user->id,
            'project_id' => $project->id,
            'event_type' => 'meeting', 'title' => 'Overnight conflict fixture',
            'event_date' => '2026-10-24', 'event_time' => '23:30', 'duration_minutes' => 120,
            'is_all_day' => false, 'is_blocking' => true, 'priority' => 'normal', 'status' => 'scheduled',
        ];
        $night = ProjectEvent::query()->create($attributes);
        $morning = ProjectEvent::query()->create(array_replace($attributes, [
            'event_date' => '2026-10-25', 'event_time' => '00:45', 'duration_minutes' => 15,
        ]));
        $service = new ProjectEventService();

        $this->assertSame([$morning->id], $service->checkEventConflicts($night)['conflicts']->pluck('id')->all());
        $this->assertSame([$night->id], $service->checkEventConflicts($morning)['conflicts']->pluck('id')->all());

        $night->update(['duration_minutes' => 30]);
        $this->assertFalse($service->checkEventConflicts($night)['has_conflicts']);
        $this->assertFalse($service->checkEventConflicts($morning)['has_conflicts']);

        $night->update(['duration_minutes' => 2000, 'end_date' => '2026-10-24']);
        $this->assertFalse($service->checkEventConflicts($night)['has_conflicts']);
    }

    public function test_exact_end_and_unknown_end_preserve_ranges_conflicts_and_recurring_intervals(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::withoutEvents(static fn () => Project::factory()->create(['organization_id' => $context->organization->id]));
        $attributes = [
            'organization_id' => $context->organization->id, 'created_by_user_id' => $context->user->id, 'project_id' => $project->id,
            'event_type' => 'meeting', 'title' => 'Exact interval fixture', 'event_date' => '2026-10-24', 'event_time' => '09:15:30',
            'end_date' => '2026-10-25', 'end_time' => '10:45:15', 'duration_minutes' => null,
            'is_all_day' => false, 'is_blocking' => true, 'priority' => 'normal', 'status' => 'scheduled',
        ];
        $exact = ProjectEvent::query()->create($attributes);
        $unknown = ProjectEvent::query()->create(array_replace($attributes, ['end_date' => null, 'end_time' => null]));
        $midnight = ProjectEvent::query()->create(array_replace($attributes, ['end_time' => '00:00:00']));
        $target = ProjectEvent::query()->create(array_replace($attributes, ['event_date' => '2026-10-25', 'event_time' => '10:45:14', 'end_time' => '11:00:00']));
        $service = new ProjectEventService();
        $day = Carbon::parse('2026-10-25');

        $this->assertEqualsCanonicalizing([$exact->id, $target->id], $service->getCalendarEvents($project->id, $day, $day)->pluck('id')->all());
        $this->assertSame([$exact->id], $service->checkEventConflicts($target)['conflicts']->pluck('id')->all());
        $this->assertTrue($target->hasConflicts());
        $this->assertFalse($unknown->hasConflicts());
        $this->assertFalse($service->checkEventConflicts($unknown)['has_conflicts']);
        $this->assertNull($unknown->getEndDateTimeAttribute());
        $this->assertNull($unknown->getCalendarEndLocal());
        $target->update(['event_time' => '10:45:15']);
        $this->assertFalse($service->checkEventConflicts($target)['has_conflicts']);
        $this->assertFalse($target->hasConflicts());
        $this->assertSame('2026-10-25T00:00:00', $midnight->getCalendarEndLocal());

        $exact->update(['is_recurring' => true, 'recurrence_pattern' => 'daily']);
        $children = $exact->generateRecurringEvents(Carbon::parse('2026-10-27'));
        $this->assertCount(3, $children);
        foreach ($children as $offset => $child) {
            $this->assertSame(Carbon::parse('2026-10-25')->addDays($offset)->format('Y-m-d') . 'T09:15:30', $child->getCalendarStartLocal());
            $this->assertSame(Carbon::parse('2026-10-26')->addDays($offset)->format('Y-m-d') . 'T10:45:15', $child->getCalendarEndLocal());
        }
    }

    public function test_ranges_include_ongoing_durations_without_including_finished_or_foreign_events(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::withoutEvents(static fn () => Project::factory()->create([
            'organization_id' => $context->organization->id,
        ]));
        $otherProject = Project::withoutEvents(static fn () => Project::factory()->create([
            'organization_id' => $context->organization->id,
        ]));
        $attributes = [
            'organization_id' => $context->organization->id,
            'created_by_user_id' => $context->user->id,
            'project_id' => $project->id,
            'event_type' => 'meeting', 'title' => 'Calendar range fixture',
            'event_date' => '2026-10-24', 'event_time' => '23:30', 'duration_minutes' => 120,
            'is_all_day' => false, 'is_blocking' => true, 'priority' => 'normal', 'status' => 'scheduled',
        ];
        $create = static fn (array $changes = []): ProjectEvent => ProjectEvent::query()->create(array_replace($attributes, $changes));
        $night = $create();
        $allDay = $create(['is_all_day' => true, 'event_time' => null, 'end_date' => '2026-10-26']);
        $target = $create(['event_date' => '2026-10-25', 'event_time' => '00:45', 'duration_minutes' => 15, 'is_blocking' => false]);
        $create(['duration_minutes' => 30]);
        $create(['duration_minutes' => 29]);
        $create(['duration_minutes' => 0]);
        $create(['is_all_day' => true]);
        $create(['duration_minutes' => 2000, 'end_date' => '2026-10-24']);
        $create(['event_date' => '2026-10-26']);
        $create(['project_id' => $otherProject->id]);
        $service = new ProjectEventService();
        $day = Carbon::parse('2026-10-25');

        $events = $service->getCalendarEvents($project->id, $day, $day);

        $this->assertEqualsCanonicalizing([$night->id, $allDay->id, $target->id], $events->pluck('id')->all());
        $blocking = $service->getBlockingEvents($project->id, $day, $day);
        $this->assertEqualsCanonicalizing([$night->id, $allDay->id], $blocking->pluck('id')->all());
        $statistics = $service->getEventsStatistics($project->id, $day, $day);
        $this->assertSame(3, $statistics['total']);
        $this->assertSame(2, $statistics['blocking_events']);
        $conflicts = $service->checkEventConflicts($target);
        $this->assertTrue($conflicts['has_conflicts']);
        $this->assertEqualsCanonicalizing([$night->id, $allDay->id], $conflicts['conflicts']->pluck('id')->all());
    }
}
