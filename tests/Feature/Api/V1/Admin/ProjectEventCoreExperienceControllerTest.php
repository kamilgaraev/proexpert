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
