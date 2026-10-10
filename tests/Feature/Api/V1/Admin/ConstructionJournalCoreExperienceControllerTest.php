<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use App\Enums\ConstructionJournal\JournalStatusEnum;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\ContractEstimateItem;
use App\Models\Contractor;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\JournalEquipment;
use App\Models\JournalEntryApprovalEvent;
use App\Models\JournalMaterial;
use App\Models\JournalWorker;
use App\Models\JournalWorkVolume;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\User;
use App\Services\Workflow\JournalScheduleTaskResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ConstructionJournalCoreExperienceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_manage_journal_entries_through_submission_and_rejection(): void
    {
        Event::fake();
        Notification::fake();

        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $anotherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        [, $contract, $estimate, $item] = $this->createCoverageFixture($context->organization, $project);
        $schedule = ProjectSchedule::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $context->user->id,
            'planned_start_date' => '2026-06-01',
            'planned_end_date' => '2026-12-31',
            'name' => 'Core experience schedule',
            'status' => 'active',
        ]);
        $task = ScheduleTask::query()->create([
            'organization_id' => $context->organization->id,
            'schedule_id' => $schedule->id,
            'created_by_user_id' => $context->user->id,
            'estimate_item_id' => $item->id,
            'name' => $item->name,
            'task_type' => 'task',
            'quantity' => 100,
            'planned_start_date' => '2026-06-01',
            'planned_end_date' => '2026-12-31',
            'status' => 'not_started',
            'planned_duration_days' => 213,
        ]);
        $anotherProjectJournal = $this->createJournal($context->organization, $anotherProject, $context->user, [
            'name' => 'Another project journal',
            'journal_number' => 'J-OTHER',
        ]);
        $this->allowAdminAccess();

        $createJournalResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/construction-journals", [
                'name' => 'Main construction journal',
                'journal_number' => 'J-001',
                'contract_id' => $contract->id,
                'start_date' => '2026-06-01',
            ]);

        $createJournalResponse->assertCreated();
        $createJournalResponse->assertJsonPath('success', true);
        $createJournalResponse->assertJsonPath('data.project_id', $project->id);
        $createJournalResponse->assertJsonPath('data.organization_id', $context->organization->id);

        $journal = ConstructionJournal::query()->findOrFail($createJournalResponse->json('data.id'));
        $this->assertSame($project->id, $journal->project_id);
        $this->assertSame($context->organization->id, $journal->organization_id);

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/construction-journals?per_page=20");

        $indexResponse->assertOk();
        $journalIds = collect($indexResponse->json('data'))->pluck('id')->all();
        $this->assertContains($journal->id, $journalIds);
        $this->assertNotContains($anotherProjectJournal->id, $journalIds);

        $entryResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/construction-journals/{$journal->id}/entries", [
                'idempotency_key' => 'journal-core-experience-1',
                'estimate_id' => $estimate->id,
                'schedule_task_id' => $task->id,
                'entry_date' => '2026-06-03',
                'work_description' => 'Foundation preparation',
                'weather_conditions' => [
                    'temperature' => 18,
                    'precipitation' => 'none',
                    'wind_speed' => 2,
                ],
                'work_volumes' => [
                    [
                        'estimate_item_id' => $item->id,
                        'quantity' => 12.5,
                        'notes' => 'Axis A-B',
                    ],
                ],
                'workers' => [
                    [
                        'specialty' => 'Concrete worker',
                        'workers_count' => 4,
                        'hours_worked' => 8,
                    ],
                ],
            ]);

        $entryResponse->assertCreated();
        $entryResponse->assertJsonPath('data.journal_id', $journal->id);
        $entryResponse->assertJsonPath('data.entry_number', 1);
        $entryResponse->assertJsonPath('data.status', 'draft');
        $entryResponse->assertJsonPath('data.workVolumes.0.quantity', 12.5);
        $entryResponse->assertJsonPath('data.workers.0.workers_count', 4);

        $entry = ConstructionJournalEntry::query()->findOrFail($entryResponse->json('data.id'));
        $this->assertSame($context->user->id, $entry->created_by_user_id);
        $this->assertDatabaseHas('journal_work_volumes', [
            'journal_entry_id' => $entry->id,
            'quantity' => '12.500',
        ]);
        $this->assertDatabaseHas('completed_works', [
            'journal_entry_id' => $entry->id,
            'status' => 'draft',
        ]);

        $submitResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/journal-entries/{$entry->id}/submit");

        $submitResponse->assertOk();
        $submitResponse->assertJsonPath('data.status', 'submitted');
        $this->assertSame(JournalEntryStatusEnum::SUBMITTED, $entry->fresh()->status);

        $lockedUpdateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/journal-entries/{$entry->id}", [
                'work_description' => 'Should not be saved while submitted',
            ]);

        $lockedUpdateResponse->assertForbidden();
        $this->assertSame('Foundation preparation', $entry->fresh()->work_description);

        $rejectResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/journal-entries/{$entry->id}/reject", [
                'reason' => 'Need additional measurements',
            ]);

        $rejectResponse->assertOk();
        $rejectResponse->assertJsonPath('data.status', 'rejected');
        $rejectResponse->assertJsonPath('data.rejection_reason', 'Need additional measurements');
        $this->assertSame($context->user->id, $entry->fresh()->approved_by_user_id);

        $reworkResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/journal-entries/{$entry->id}", [
                'work_description' => 'Foundation preparation with checked measurements',
                'work_volumes' => [
                    [
                        'id' => JournalWorkVolume::query()->where('journal_entry_id', $entry->id)->value('id'),
                        'estimate_item_id' => $item->id,
                        'quantity' => 14,
                        'notes' => 'Adjusted after measurement',
                    ],
                ],
            ]);

        $reworkResponse->assertOk();
        $reworkResponse->assertJsonPath('data.work_description', 'Foundation preparation with checked measurements');
        $reworkResponse->assertJsonPath('data.workVolumes.0.quantity', 14);
        $this->assertSame('Foundation preparation with checked measurements', $entry->fresh()->work_description);

        $entriesResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/construction-journals/{$journal->id}/entries?status=rejected");

        $entriesResponse->assertOk();
        $entriesResponse->assertJsonPath('summary.total_entries', 1);
        $entriesResponse->assertJsonPath('summary.rejected_entries', 1);
        $entryIds = collect($entriesResponse->json('data'))->pluck('id')->all();
        $this->assertSame([$entry->id], $entryIds);

        $foreignJournalShowResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/construction-journals/{$anotherProjectJournal->id}");

        $foreignJournalShowResponse->assertOk();
    }

    public function test_journal_rejects_foreign_contracts_and_foreign_entries_are_forbidden(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignContext = AdminApiTestContext::create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $foreignJournal = $this->createJournal($foreignContext->organization, $foreignProject, $foreignContext->user);
        $foreignEntry = $this->createEntry($foreignJournal, $foreignContext->user);
        $this->allowAdminAccess();

        $foreignEntryResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/journal-entries/{$foreignEntry->id}");

        $foreignEntryResponse->assertForbidden();

        $foreignContractResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/construction-journals", [
                'name' => 'Journal with invalid contract',
                'contract_id' => 999999,
                'start_date' => '2026-06-01',
            ]);

        $foreignContractResponse->assertStatus(422);
        $foreignContractResponse->assertJsonPath('success', false);
        $this->assertDatabaseMissing('construction_journals', [
            'project_id' => $project->id,
            'name' => 'Journal with invalid contract',
        ]);
    }

    public function test_journal_pages_batch_entry_history_and_completed_work_counts(): void
    {
        Event::fake();
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        [$contractor, $contract, $estimate, $item] = $this->createCoverageFixture($context->organization, $project);
        $journal = $this->createJournal($context->organization, $project, $context->user, ['contract_id' => $contract->id]);
        $this->allowAdminAccess();

        for ($number = 1; $number <= 8; $number++) {
            $entry = $this->createEntry($journal, $context->user, ['entry_number' => $number]);
            JournalWorkVolume::query()->create([
                'journal_entry_id' => $entry->id,
                'estimate_item_id' => $item->id,
                'quantity' => 1,
            ]);
            JournalMaterial::query()->create([
                'journal_entry_id' => $entry->id,
                'estimate_item_id' => $item->id,
                'material_name' => 'Page material',
                'quantity' => 1,
                'measurement_unit' => 'шт',
            ]);
            JournalEquipment::query()->create([
                'journal_entry_id' => $entry->id,
                'estimate_item_id' => $item->id,
                'equipment_name' => 'Page equipment',
                'quantity' => 1,
            ]);
            JournalWorker::query()->create([
                'journal_entry_id' => $entry->id,
                'estimate_item_id' => $item->id,
                'specialty' => 'Page worker',
                'workers_count' => 1,
            ]);
            JournalEntryApprovalEvent::query()->create([
                'journal_entry_id' => $entry->id,
                'organization_id' => $context->organization->id,
                'project_id' => $project->id,
                'actor_user_id' => $context->user->id,
                'event' => 'created',
                'from_status' => 'draft',
                'to_status' => 'draft',
                'occurred_at' => now(),
            ]);

            for ($workNumber = 0; $workNumber < $number % 3; $workNumber++) {
                CompletedWork::query()->create([
                    'organization_id' => $context->organization->id,
                    'project_id' => $project->id,
                    'journal_entry_id' => $entry->id,
                    'quantity' => 1,
                    'completion_date' => '2026-06-03',
                    'status' => 'confirmed',
                ]);
            }
        }

        foreach (['/entries?per_page=20' => 'data', '' => 'data.entries'] as $suffix => $entriesPath) {
            DB::enableQueryLog();
            DB::flushQueryLog();

            try {
                $response = $this->withHeaders($context->authHeaders())
                    ->getJson("/api/v1/admin/construction-journals/{$journal->id}{$suffix}");
                $queries = collect(DB::getQueryLog())->pluck('query');
            } finally {
                DB::disableQueryLog();
            }

            $response->assertOk()->assertJsonCount(8, $entriesPath);
            foreach ($response->json($entriesPath) as $payload) {
                $this->assertSame($payload['entry_number'] % 3, $payload['completed_works_count']);
                $this->assertSame([], $payload['completed_works']);
                $this->assertSame('created', $payload['approval_history'][0]['event']);
                $this->assertSame($context->user->id, $payload['approval_history'][0]['actor']['id']);
                $this->assertContains('update', $payload['available_actions']);
                $this->assertContains('submit', $payload['available_actions']);
                $this->assertSame('covered', $payload['workVolumes'][0]['contract_coverage_status']);
                $this->assertSame($contract->id, $payload['workVolumes'][0]['contract_id']);
                $this->assertSame($contractor->name, $payload['workVolumes'][0]['contractor_name']);
                foreach (['workVolumes', 'materials', 'equipment', 'workers'] as $relation) {
                    $this->assertSame($item->id, $payload[$relation][0]['estimateItem']['id']);
                }
            }

            $this->assertCount(1, $queries->filter(fn (string $sql): bool => str_contains($sql, 'from "journal_entry_approval_events"')));
            $this->assertCount(1, $queries->filter(fn (string $sql): bool => str_contains($sql, 'from "completed_works"')));
            $this->assertCount(1, $queries->filter(fn (string $sql): bool => str_contains($sql, 'from "contract_estimate_items"')));
            $this->assertCount(4, $queries->filter(fn (string $sql): bool => str_contains($sql, 'from "estimate_items"')));
            $this->assertLessThanOrEqual(4, $queries->filter(fn (string $sql): bool => str_contains($sql, 'from "construction_journal_entries"'))->count());
            $this->assertCount(1, $queries->filter(fn (string $sql): bool => str_contains($sql, 'from "schedule_tasks"')));
        }
    }

    public function test_page_task_resolution_preserves_project_scope_ambiguity_and_live_fallback(): void
    {
        Event::fake();
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        [, , , $item] = $this->createCoverageFixture($context->organization, $project);
        $journal = $this->createJournal($context->organization, $project, $context->user);
        $entry = $this->createEntry($journal, $context->user);
        JournalWorkVolume::query()->create(['journal_entry_id' => $entry->id, 'estimate_item_id' => $item->id, 'quantity' => 1]);
        $makeSchedule = fn (Project $project): ProjectSchedule => ProjectSchedule::query()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'created_by_user_id' => $context->user->id,
            'name' => 'Resolution schedule',
            'planned_start_date' => '2026-06-01',
            'planned_end_date' => '2026-06-30',
        ]);
        $makeTask = fn (ProjectSchedule $schedule): ScheduleTask => ScheduleTask::query()->create([
            'organization_id' => $schedule->organization_id,
            'schedule_id' => $schedule->id,
            'estimate_item_id' => $item->id,
            'created_by_user_id' => $context->user->id,
            'name' => 'Resolution task',
            'planned_start_date' => '2026-06-01',
            'planned_end_date' => '2026-06-30',
            'planned_duration_days' => 30,
        ]);
        $schedule = $makeSchedule($project);
        $task = $makeTask($schedule);
        $makeTask($schedule)->delete();
        $deletedSchedule = $makeSchedule($project);
        $makeTask($deletedSchedule);
        $deletedSchedule->delete();
        $foreignProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $makeTask($makeSchedule($foreignProject));
        $resolver = app(JournalScheduleTaskResolver::class);

        $entry->load(['journal', 'workVolumes']);
        $resolver->loadForEntryPage(collect([$entry]));
        $this->assertSame($task->id, $resolver->resolveForVolume($entry, $entry->workVolumes->first())->id);
        $this->assertTrue($resolver->allVolumesHaveResolvableTask($entry));

        $duplicate = $makeTask($schedule);
        $entry = $entry->fresh(['journal', 'workVolumes']);
        $resolver->loadForEntryPage(collect([$entry]));
        $this->assertNull($resolver->resolveForVolume($entry, $entry->workVolumes->first()));
        $this->assertFalse($resolver->allVolumesHaveResolvableTask($entry));

        $duplicate->delete();
        $entry = $entry->fresh(['journal', 'workVolumes']);
        $this->assertFalse($entry->workVolumes->first()->relationLoaded('journalScheduleTasks'));
        $this->assertSame($task->id, $resolver->resolveForVolume($entry, $entry->workVolumes->first())->id);

        $entry->schedule_task_id = $task->id;
        $entry->setRelation('scheduleTask', $task);
        $resolver->loadForEntryPage(collect([$entry]));
        $this->assertFalse($entry->workVolumes->first()->relationLoaded('journalScheduleTasks'));
        $this->assertSame($task->id, $resolver->resolveForVolume($entry, $entry->workVolumes->first())->id);

        $entry = $entry->fresh(['journal', 'workVolumes']);
        $event = 'eloquent.retrieved: '.ScheduleTask::class;
        Event::fakeExcept([$event]);
        Event::listen($event, function (ScheduleTask $retrieved) use ($task, $schedule): void {
            if ($retrieved->id === $task->id) {
                ProjectSchedule::query()->whereKey($schedule->id)->delete();
            }
        });
        try {
            $resolver->loadForEntryPage(collect([$entry]));
            $this->assertNull($resolver->resolveForVolume($entry, $entry->workVolumes->first()));
        } finally {
            Event::forget($event);
        }
    }

    private function createCoverageFixture(Organization $organization, Project $project): array
    {
        $contractor = Contractor::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Page contractor',
        ]);
        $contract = Contract::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => 'PAGE-CONTRACT',
            'date' => '2026-06-01',
            'subject' => 'Page works',
            'total_amount' => 100,
            'status' => 'active',
        ]);
        $estimate = Estimate::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'name' => 'Page estimate',
            'number' => 'PAGE-ESTIMATE',
            'estimate_date' => '2026-06-01',
            'status' => 'approved',
        ]);
        $item = EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'position_number' => '1',
            'item_type' => 'work',
            'name' => 'Page item',
            'measurement_unit_id' => \App\Models\MeasurementUnit::query()->firstOrCreate(['organization_id' => $organization->id, 'short_name' => 'м'], ['name' => 'Метр', 'type' => 'work', 'is_system' => false])->id,
            'quantity' => 100,
            'quantity_total' => 100,
            'unit_price' => 1,
            'total_amount' => 100,
        ]);
        ContractEstimateItem::query()->create([
            'contract_id' => $contract->id,
            'estimate_id' => $estimate->id,
            'estimate_item_id' => $item->id,
            'quantity' => 100,
            'amount' => 100,
        ]);
        return [$contractor, $contract, $estimate, $item];
    }

    private function createJournal(
        Organization $organization,
        Project $project,
        User $user,
        array $overrides = []
    ): ConstructionJournal {
        return ConstructionJournal::query()->create(array_merge([
            'organization_id' => $organization->id,
            'performing_organization_id' => $organization->id,
            'project_id' => $project->id,
            'name' => 'Journal ' . random_int(1000, 9999),
            'journal_number' => 'J-' . random_int(1000, 9999),
            'start_date' => '2026-06-01',
            'status' => JournalStatusEnum::ACTIVE,
            'created_by_user_id' => $user->id,
        ], $overrides));
    }

    private function createEntry(ConstructionJournal $journal, User $user, array $overrides = []): ConstructionJournalEntry
    {
        return ConstructionJournalEntry::query()->create(array_merge([
            'journal_id' => $journal->id,
            'entry_date' => '2026-06-03',
            'entry_number' => 1,
            'work_description' => 'Existing entry',
            'status' => JournalEntryStatusEnum::DRAFT,
            'created_by_user_id' => $user->id,
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
