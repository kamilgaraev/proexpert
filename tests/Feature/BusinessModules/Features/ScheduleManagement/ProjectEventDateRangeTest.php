<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Features\ScheduleManagement;

use App\BusinessModules\Features\ScheduleManagement\Models\ProjectEvent;
use App\BusinessModules\Features\ScheduleManagement\Services\ProjectEventService;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ProjectEventDateRangeTest extends TestCase
{
    use RefreshDatabase;

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
