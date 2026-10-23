<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\ScheduleManagement\Models\ProjectEvent;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ProjectEventCoreExperienceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_manage_project_events_statistics_and_conflicts_in_project_scope(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $anotherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignEvent = $this->createProjectEvent($anotherProject->id, $context->organization->id, $context->user->id, [
            'title' => 'Foreign project event',
            'event_date' => '2026-06-03',
        ]);
        $blockingEvent = $this->createProjectEvent($project->id, $context->organization->id, $context->user->id, [
            'title' => 'Blocking inspection',
            'event_type' => 'inspection',
            'event_date' => '2026-06-03',
            'duration_minutes' => 90,
            'is_blocking' => true,
            'priority' => 'high',
        ]);
        $this->allowAdminAccess();

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/events", [
                'event_type' => 'meeting',
                'title' => 'Coordination meeting',
                'event_date' => '2026-06-03',
                'event_time' => '10:00',
                'duration_minutes' => 90,
                'priority' => 'normal',
                'status' => 'scheduled',
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('data.project_id', $project->id);
        $createResponse->assertJsonPath('data.organization_id', $context->organization->id);
        $eventId = $createResponse->json('data.id');

        $calendarResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/events/calendar?start_date=2026-06-01&end_date=2026-06-30");

        $calendarResponse->assertOk();
        $calendarIds = collect($calendarResponse->json('data'))->pluck('id')->all();
        $this->assertContains($eventId, $calendarIds);
        $this->assertContains($blockingEvent->id, $calendarIds);
        $this->assertNotContains($foreignEvent->id, $calendarIds);

        $statisticsResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/events/statistics?start_date=2026-06-01&end_date=2026-06-30");

        $statisticsResponse->assertOk();
        $statisticsResponse->assertJsonPath('data.total', 2);
        $statisticsResponse->assertJsonPath('data.blocking_events', 1);
        $statisticsResponse->assertJsonMissingPath('data.blocking_count');

        $conflictsResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/events/{$eventId}/conflicts");

        $conflictsResponse->assertOk();
        $conflictsResponse->assertJsonPath('data.has_conflicts', true);
        $conflictsResponse->assertJsonPath('data.conflicts.0.id', $blockingEvent->id);

        $updateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/projects/{$project->id}/events/{$eventId}", [
                'title' => 'Updated coordination meeting',
                'status' => 'completed',
            ]);

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('data.title', 'Updated coordination meeting');
        $updateResponse->assertJsonPath('data.status', 'completed');

        $deleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/projects/{$project->id}/events/{$eventId}");

        $deleteResponse->assertOk();
        $this->assertSoftDeleted('project_events', ['id' => $eventId]);
    }

    public function test_saving_a_recurring_root_without_a_new_end_preserves_existing_occurrences(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $root = $this->createProjectEvent($project->id, $context->organization->id, $context->user->id, [
            'event_date' => '2026-10-20', 'is_recurring' => true, 'recurrence_pattern' => 'daily',
        ]);
        $child = $this->createProjectEvent($project->id, $context->organization->id, $context->user->id, [
            'event_date' => '2026-10-21', 'recurring_parent_id' => $root->id, 'status' => 'completed',
        ]);
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/projects/{$project->id}/events/{$root->id}", [
                'title' => 'Updated root title', 'event_date' => '2026-10-20',
                'is_recurring' => true, 'recurrence_pattern' => 'daily',
            ]);

        $response->assertOk()->assertJsonPath('data.title', 'Updated root title');
        $this->assertSame([$child->id], $root->recurringChildren()->pluck('id')->all());
        $this->assertSame('completed', $child->fresh()->status);
    }

    public function test_changing_recurrence_without_an_end_is_rejected_without_removing_children(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $root = $this->createProjectEvent($project->id, $context->organization->id, $context->user->id, [
            'event_date' => '2026-10-20', 'is_recurring' => true, 'recurrence_pattern' => 'daily',
        ]);
        $child = $this->createProjectEvent($project->id, $context->organization->id, $context->user->id, [
            'event_date' => '2026-10-21', 'recurring_parent_id' => $root->id,
        ]);
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/projects/{$project->id}/events/{$root->id}", [
                'title' => 'Must not persist', 'recurrence_pattern' => 'weekly',
            ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('recurrence_until');
        $this->assertSame('daily', $root->fresh()->recurrence_pattern);
        $this->assertSame('Project event', $root->fresh()->title);
        $this->assertSame([$child->id], $root->recurringChildren()->pluck('id')->all());
    }

    public function test_create_rejects_a_recurrence_end_before_the_event_date(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/events", [
                'event_type' => 'meeting', 'title' => 'Invalid recurrence',
                'event_date' => '2026-10-20', 'is_recurring' => true,
                'recurrence_pattern' => 'daily', 'recurrence_until' => '2026-10-19',
            ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('recurrence_until');
        $this->assertSame(0, ProjectEvent::query()->where('project_id', $project->id)->count());
    }

    public function test_partial_update_validates_recurrence_end_against_the_existing_date(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $root = $this->createProjectEvent($project->id, $context->organization->id, $context->user->id, [
            'event_date' => '2026-10-20', 'is_recurring' => true, 'recurrence_pattern' => 'daily',
        ]);
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/projects/{$project->id}/events/{$root->id}", [
                'recurrence_until' => '2026-10-19',
            ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('recurrence_until');
        $this->assertSame('2026-10-20', $root->fresh()->event_date->format('Y-m-d'));
    }

    public function test_explicit_recurrence_rebuild_with_a_valid_end_remains_available(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $root = $this->createProjectEvent($project->id, $context->organization->id, $context->user->id, [
            'event_date' => '2026-10-20', 'is_recurring' => true, 'recurrence_pattern' => 'daily',
        ]);
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/projects/{$project->id}/events/{$root->id}", [
                'recurrence_pattern' => 'daily', 'recurrence_until' => '2026-10-23',
            ]);

        $response->assertOk();
        $dates = $root->recurringChildren()->orderBy('event_date')->get()
            ->map(static fn (ProjectEvent $event): string => $event->event_date->format('Y-m-d'))->all();
        $this->assertSame(['2026-10-21', '2026-10-22', '2026-10-23'], $dates);
    }

    public function test_calendar_preserves_wall_clock_fields_and_legacy_iso_values(): void
    {
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');

        try {
            $context = AdminApiTestContext::create();
            $project = Project::withoutEvents(static fn () => Project::factory()->create([
                'organization_id' => $context->organization->id,
            ]));
            $cases = [
                [['event_date' => '2026-03-29', 'event_time' => '10:00', 'duration_minutes' => 60],
                    '2026-03-29T10:00:00', '2026-03-29T11:00:00', '2026-03-29T10:00:00+02:00'],
                [['event_date' => '2026-03-29', 'event_time' => '02:30', 'duration_minutes' => 60],
                    '2026-03-29T02:30:00', '2026-03-29T03:30:00', '2026-03-29T03:30:00+02:00'],
                [['event_date' => '2026-03-30', 'event_time' => '23:30', 'duration_minutes' => 120],
                    '2026-03-30T23:30:00', '2026-03-31T01:30:00', '2026-03-30T23:30:00+02:00'],
                [['event_date' => '2026-03-29', 'event_time' => null, 'is_all_day' => true],
                    '2026-03-29', '2026-03-30', '2026-03-29T00:00:00+01:00'],
                [['event_date' => '2026-10-24', 'event_time' => null, 'is_all_day' => true, 'end_date' => '2026-10-26'],
                    '2026-10-24', '2026-10-27', '2026-10-24T00:00:00+02:00'],
                [['event_date' => '2026-03-29', 'event_time' => '10:00', 'end_date' => '2026-03-30'],
                    '2026-03-29T10:00:00', '2026-03-30T23:59:59', '2026-03-29T10:00:00+02:00'],
            ];
            $events = [];
            foreach ($cases as [$attributes, $start, $end, $legacyStart]) {
                $event = $this->createProjectEvent($project->id, $context->organization->id, $context->user->id, $attributes);
                $events[] = [$event, $start, $end, $legacyStart];
            }
            $this->allowAdminAccess();

            $response = $this->withHeaders($context->authHeaders())
                ->getJson("/api/v1/admin/projects/{$project->id}/events/calendar?start_date=2026-03-01&end_date=2026-10-31");

            $response->assertOk();
            $rows = collect($response->json('data'))->keyBy('id');
            $this->assertCount(count($cases), $rows);
            foreach ($events as [$event, $start, $end, $legacyStart]) {
                $row = $rows->get($event->id);
                $this->assertSame($start, $row['start_local'] ?? null);
                $this->assertSame($end, $row['end_local'] ?? null);
                $this->assertSame($legacyStart, $row['start']);
                $this->assertSame($event->getEndDateTimeAttribute()->toIso8601String(), $row['end']);
            }
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    }

    private function createProjectEvent(int $projectId, int $organizationId, int $userId, array $overrides = []): ProjectEvent
    {
        return ProjectEvent::query()->create(array_merge([
            'project_id' => $projectId,
            'organization_id' => $organizationId,
            'created_by_user_id' => $userId,
            'event_type' => 'meeting',
            'title' => 'Project event',
            'event_date' => '2026-06-01',
            'event_time' => '09:00',
            'duration_minutes' => 60,
            'is_all_day' => false,
            'is_blocking' => false,
            'priority' => 'normal',
            'status' => 'scheduled',
        ], $overrides));
    }

    private function allowAdminAccess(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
        });
    }
}
