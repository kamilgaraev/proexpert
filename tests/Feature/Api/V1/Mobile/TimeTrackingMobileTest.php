<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\TimeTracking\Reporting\ApprovedTimeEntryReportingFactRecorder;
use App\BusinessModules\Features\TimeTracking\Reporting\Models\ApprovedTimeEntryReportingFact;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Mobile\MobileMutationIdempotency;
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

        $timer = $this->withHeaders($context->mobileAuthHeaders())
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

        $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/time-tracking/daily-summary?date=2026-05-22&project_id=' . $project->id)
            ->assertOk()
            ->assertJsonPath('data.active_timer.id', $timer['id'])
            ->assertJsonPath('data.totals.by_status.draft', 1);

        $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/time-tracking/entries/' . $timer['id'] . '/stop', [
                'end_time' => '12:00',
                'break_time' => 0.5,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active_timer', false)
            ->assertJsonPath('data.hours_worked', 3.5);

        $manual = $this->withHeaders($context->mobileAuthHeaders())
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

        $this->withHeaders($context->mobileAuthHeaders())
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

    public function test_mobile_manual_entry_and_timer_start_replay_without_duplicate_mutations(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess(['time_tracking.create']);
        $headers = [...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'manual-replay-key-0001'];
        $manualPayload = [
            'project_id' => $project->id,
            'work_date' => '2026-05-22',
            'hours_worked' => 2.25,
            'title' => 'Проверка геометрии',
            'is_billable' => false,
        ];

        $manualFirst = $this->withHeaders($headers)
            ->postJson('/api/v1/mobile/time-tracking/entries', $manualPayload)
            ->assertCreated()
            ->json();
        $manualReplay = $this->withHeaders($headers)
            ->postJson('/api/v1/mobile/time-tracking/entries', $manualPayload)
            ->assertCreated()
            ->json();

        self::assertSame($manualFirst['data'], $manualReplay['data']);
        self::assertDatabaseCount('time_entries', 1);

        $timerHeaders = [...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'timer-replay-key-0001'];
        $timerPayload = [
            'project_id' => $project->id,
            'work_date' => '2026-05-23',
            'start_time' => '08:00',
            'title' => 'Монтаж опалубки',
            'is_billable' => true,
        ];
        $timerFirst = $this->withHeaders($timerHeaders)
            ->postJson('/api/v1/mobile/time-tracking/timer/start', $timerPayload)
            ->assertCreated()
            ->json();
        $timerReplay = $this->withHeaders($timerHeaders)
            ->postJson('/api/v1/mobile/time-tracking/timer/start', $timerPayload)
            ->assertCreated()
            ->json();

        self::assertSame($timerFirst['data'], $timerReplay['data']);
        self::assertDatabaseCount('time_entries', 2);
    }

    public function test_mobile_idempotency_key_conflicts_on_changed_payload_and_is_scoped_by_org_and_user(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess(['time_tracking.create']);
        $headers = [...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'same-idempotency-key-001'];
        $payload = [
            'project_id' => $project->id,
            'work_date' => '2026-05-22',
            'hours_worked' => 2,
            'title' => 'Проверка',
            'is_billable' => true,
        ];

        $first = $this->withHeaders($headers)
            ->postJson('/api/v1/mobile/time-tracking/entries', $payload)
            ->assertCreated()
            ->json('data.id');
        $this->withHeaders($headers)
            ->postJson('/api/v1/mobile/time-tracking/entries', [...$payload, 'hours_worked' => 3])
            ->assertStatus(409);

        $otherContext = AdminApiTestContext::create(roleSlug: 'foreman');
        $otherProject = Project::factory()->create(['organization_id' => $otherContext->organization->id]);
        $otherEntry = $this->timeEntry($otherContext, $otherProject);
        $otherUser = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $userScopedEntry = $this->timeEntry($context, $project, ['user_id' => $otherUser->id]);
        $idempotency = app(MobileMutationIdempotency::class);

        $otherOrganizationReplay = $idempotency->run(
            (int) $otherContext->organization->id,
            (int) $otherContext->user->id,
            'same-idempotency-key-001',
            'time_tracking.manual_entry',
            $payload,
            fn () => $otherEntry,
            fn (int $entryId): ?TimeEntry => TimeEntry::query()
                ->where('organization_id', $otherContext->organization->id)
                ->where('user_id', $otherContext->user->id)
                ->find($entryId)
        );
        $otherUserReplay = $idempotency->run(
            (int) $context->organization->id,
            (int) $otherUser->id,
            'same-idempotency-key-001',
            'time_tracking.manual_entry',
            $payload,
            fn () => $userScopedEntry,
            fn (int $entryId): ?TimeEntry => TimeEntry::query()
                ->where('organization_id', $context->organization->id)
                ->where('user_id', $otherUser->id)
                ->find($entryId)
        );

        self::assertNotSame($first, $otherOrganizationReplay->getKey());
        self::assertSame($otherEntry->getKey(), $otherOrganizationReplay->getKey());
        self::assertSame($userScopedEntry->getKey(), $otherUserReplay->getKey());
        $this->assertDatabaseCount('mobile_mutation_idempotencies', 3);
    }

    public function test_mobile_timer_stop_replay_is_scoped_to_the_entry_id(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $entry = $this->timeEntry($context, $project, [
            'start_time' => '08:00',
            'hours_worked' => null,
            'custom_fields' => ['mobile_time_tracking' => ['active_timer' => true, 'corrections' => []]],
        ]);
        $this->allowAccess(['time_tracking.edit']);
        $headers = [...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'timer-stop-replay-0001'];
        $payload = ['end_time' => '12:00', 'break_time' => 0.5];
        $first = $this->withHeaders($headers)
            ->postJson('/api/v1/mobile/time-tracking/entries/' . $entry->id . '/stop', $payload)
            ->assertOk()
            ->json();
        $replay = $this->withHeaders($headers)
            ->postJson('/api/v1/mobile/time-tracking/entries/' . $entry->id . '/stop', $payload)
            ->assertOk()
            ->json();

        self::assertSame($first['data'], $replay['data']);
        self::assertDatabaseCount('time_entries', 1);
        $otherEntry = $this->timeEntry($context, $project, [
            'start_time' => '08:00',
            'hours_worked' => null,
            'custom_fields' => ['mobile_time_tracking' => ['active_timer' => true, 'corrections' => []]],
        ]);
        $this->withHeaders($headers)
            ->postJson('/api/v1/mobile/time-tracking/entries/' . $otherEntry->id . '/stop', $payload)
            ->assertStatus(409);
        $this->assertDatabaseHas('time_entries', ['id' => $otherEntry->id, 'hours_worked' => null]);
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

        $this->withHeaders($context->mobileAuthHeaders())
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

        $this->withHeaders($context->mobileAuthHeaders())
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

    public function test_mobile_entry_list_is_limited_to_the_actor_and_organization(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $ownEntry = $this->timeEntry($context, $project, ['title' => 'Моя запись']);
        $otherUser = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $this->timeEntry($context, $project, ['user_id' => $otherUser->id, 'title' => 'Чужая запись']);
        $foreignContext = AdminApiTestContext::create(roleSlug: 'foreman');
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $foreignEntry = $this->timeEntry($foreignContext, $foreignProject, ['title' => 'Другая организация']);
        $this->allowAccess(['time_tracking.view']);

        $response = $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/time-tracking/entries?project_id=' . $project->id)
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $ownEntry->id);

        self::assertSame([$ownEntry->id], array_column($response->json('data.items'), 'id'));
        self::assertNotContains($foreignEntry->id, array_column($response->json('data.items'), 'id'));
    }

    public function test_mobile_rejection_requires_its_permission_and_submitted_state(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'worker');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $entry = $this->timeEntry($context, $project, ['status' => 'submitted']);
        $this->allowAccess(['time_tracking.view', 'time_tracking.reject']);

        $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/time-tracking/entries/' . $entry->id . '/reject', [
                'reason' => 'Не совпадают подтвержденные часы',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Не совпадают подтвержденные часы');

        $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/time-tracking/entries/' . $entry->id . '/reject', [
                'reason' => 'Повторное отклонение',
            ])
            ->assertStatus(409);
    }

    public function test_mobile_rejection_requires_its_separate_permission(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'worker');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $entry = $this->timeEntry($context, $project, ['status' => 'submitted']);
        $this->allowAccess(['time_tracking.view']);

        $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/time-tracking/entries/' . $entry->id . '/reject', [
                'reason' => 'Не совпадают подтвержденные часы',
            ])
            ->assertStatus(403);
    }

    public function test_mobile_approves_unpriced_manual_time_without_fabricating_money(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess(['time_tracking.view', 'time_tracking.create', 'time_tracking.submit', 'time_tracking.approve']);

        foreach ([false, true] as $billable) {
            $entry = $this->withHeaders($context->mobileAuthHeaders())
                ->postJson('/api/v1/mobile/time-tracking/entries', [
                    'project_id' => $project->id,
                    'work_date' => '2026-05-22',
                    'hours_worked' => 0.01,
                    'title' => $billable ? 'Время без тарифа' : 'Время без оплаты',
                    'is_billable' => $billable,
                ])
                ->assertCreated()
                ->json('data');

            $this->withHeaders($context->mobileAuthHeaders())
                ->postJson('/api/v1/mobile/time-tracking/entries/' . $entry['id'] . '/submit')
                ->assertOk();

            $this->withHeaders($context->mobileAuthHeaders())
                ->postJson('/api/v1/mobile/time-tracking/entries/' . $entry['id'] . '/approve')
                ->assertOk()
                ->assertJsonPath('data.status', 'approved')
                ->assertJsonPath('data.hours_worked', 0.01);

            $this->assertDatabaseHas('time_entry_approval_reporting_facts', [
                'organization_id' => $context->organization->id,
                'time_entry_id' => $entry['id'],
                'project_id' => $project->id,
                'hours' => '0.01',
                'currency' => null,
                'hourly_rate_minor' => null,
                'cost_minor' => null,
                'quality_status' => 'partial',
            ]);
            $fact = ApprovedTimeEntryReportingFact::query()->where('time_entry_id', $entry['id'])->sole();
            $replayed = (new ApprovedTimeEntryReportingFactRecorder)->record(TimeEntry::query()->findOrFail($entry['id']));
            self::assertSame($fact->id, $replayed->id);
            self::assertSame($fact->source_hash, $replayed->source_hash);
        }

        $this->assertDatabaseCount('time_entry_approval_reporting_facts', 2);
    }

    public function test_mobile_approval_preserves_priced_cost_and_reporting_replay(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess(['time_tracking.approve']);
        $entry = $this->timeEntry($context, $project, [
            'status' => 'submitted',
            'hours_worked' => 0.01,
            'hourly_rate' => 1500,
            'custom_fields' => ['rate_currency' => 'rub'],
        ]);

        $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/time-tracking/entries/' . $entry->id . '/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $fact = ApprovedTimeEntryReportingFact::query()->where('time_entry_id', $entry->id)->sole();
        self::assertSame('RUB', $fact->currency);
        self::assertSame('time_entry_rate', $fact->currency_source);
        self::assertSame(150000, (int) $fact->hourly_rate_minor);
        self::assertSame(1500, (int) $fact->cost_minor);
        self::assertSame('complete', $fact->quality_status);

        $replayed = (new ApprovedTimeEntryReportingFactRecorder)->record($entry->refresh());
        self::assertSame($fact->id, $replayed->id);
        self::assertSame($fact->source_hash, $replayed->source_hash);
        $this->assertDatabaseCount('time_entry_approval_reporting_facts', 1);
    }

    public function test_mobile_priced_approval_rejects_missing_or_invalid_currency_atomically(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess(['time_tracking.approve']);

        foreach ([[], ['rate_currency' => 'RUBLE']] as $customFields) {
            $entry = $this->timeEntry($context, $project, [
                'status' => 'submitted',
                'hourly_rate' => 1500,
                'custom_fields' => $customFields,
            ]);

            $this->withHeaders($context->mobileAuthHeaders())
                ->postJson('/api/v1/mobile/time-tracking/entries/' . $entry->id . '/approve')
                ->assertStatus(409);

            $entry->refresh();
            self::assertSame('submitted', $entry->status);
            self::assertNull($entry->approved_at);
            self::assertNull($entry->approved_by_user_id);
        }

        $this->assertDatabaseCount('time_entry_approval_reporting_facts', 0);
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
