<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class TimeTrackingMobileTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_user_starts_stops_creates_and_submits_time_entries(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess(['time_tracking.view', 'time_tracking.create', 'time_tracking.edit', 'time_tracking.submit']);

        $timer = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/time-tracking/timer/start', [
                'project_id' => $project->id,
                'work_date' => '2026-05-22',
                'start_time' => '08:00',
                'title' => 'Монтаж опалубки',
                'is_billable' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_active_timer', true)
            ->assertJsonPath('data.hours_worked', null)
            ->json('data');

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/mobile/time-tracking/daily-summary?date=2026-05-22&project_id=' . $project->id)
            ->assertOk()
            ->assertJsonPath('data.active_timer.id', $timer['id'])
            ->assertJsonPath('data.totals.by_status.draft', 1);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/time-tracking/entries/' . $timer['id'] . '/stop', [
                'end_time' => '12:00',
                'break_time' => 0.5,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active_timer', false)
            ->assertJsonPath('data.hours_worked', 3.5);

        $manual = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/time-tracking/entries', [
                'project_id' => $project->id,
                'work_date' => '2026-05-22',
                'hours_worked' => 2.25,
                'title' => 'Проверка геометрии',
                'is_billable' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data');

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/time-tracking/entries/' . $manual['id'] . '/submit')
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.approval_summary.status', 'submitted');

        $this->assertDatabaseHas('time_entries', [
            'id' => $timer['id'],
            'hours_worked' => 3.5,
            'status' => 'draft',
        ]);
        $this->assertDatabaseHas('time_entries', [
            'id' => $manual['id'],
            'status' => 'submitted',
        ]);
    }

    public function test_mobile_user_submits_rejected_entry_correction(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $entry = $this->timeEntry($context, $project, [
            'status' => 'rejected',
            'hours_worked' => 4,
            'rejection_reason' => 'Не совпали часы',
        ]);
        $this->allowAccess(['time_tracking.view', 'time_tracking.edit', 'time_tracking.submit']);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/time-tracking/entries/' . $entry->id . '/correction', [
                'hours_worked' => 5.5,
                'correction_reason' => 'Добавлен фактический демонтаж',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.hours_worked', 5.5)
            ->assertJsonPath('data.corrections.0.reason', 'Добавлен фактический демонтаж');

        $this->assertDatabaseHas('time_entries', [
            'id' => $entry->id,
            'hours_worked' => 5.5,
            'status' => 'submitted',
        ]);
    }

    public function test_mobile_time_tracking_actions_require_permissions(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'worker');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess(['time_tracking.view']);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/time-tracking/timer/start', [
                'project_id' => $project->id,
                'work_date' => '2026-05-22',
                'start_time' => '08:00',
                'title' => 'Монтаж опалубки',
                'is_billable' => true,
            ])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'PERMISSION_DENIED');
    }

    private function timeEntry(AdminApiTestContext $context, Project $project, array $attributes = []): TimeEntry
    {
        return TimeEntry::query()->create(array_merge([
            'organization_id' => $context->organization->id,
            'user_id' => $context->user->id,
            'worker_type' => 'user',
            'project_id' => $project->id,
            'work_date' => '2026-05-22',
            'hours_worked' => 4,
            'break_time' => 0,
            'title' => 'Монтаж опалубки',
            'status' => 'draft',
            'is_billable' => true,
            'custom_fields' => [
                'mobile_time_tracking' => [
                    'active_timer' => false,
                    'corrections' => [],
                ],
            ],
        ], $attributes));
    }

    private function allowAccess(array $allowedPermissions): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });

        $this->mock(AuthorizationService::class, function (MockInterface $mock) use ($allowedPermissions): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturnUsing(
                static fn (User $user, string $permission): bool => in_array($permission, $allowedPermissions, true)
            );
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['foreman']);
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
