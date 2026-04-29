<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\BusinessModules\Features\BudgetEstimates\Services\ConstructionJournalService;
use App\BusinessModules\Features\BudgetEstimates\Services\Integration\EstimateCoverageService;
use App\BusinessModules\Features\BudgetEstimates\Services\JournalApprovalService;
use App\Enums\EstimatePositionItemType;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Resources\Api\V1\Admin\Contract\ContractEstimateItemResource;
use App\Models\CompletedWork;
use App\Models\ConstructionJournal;
use App\Models\Contract;
use App\Models\ContractEstimateItem;
use App\Models\Contractor;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\JournalWorkVolume;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\User;
use App\Services\CompletedWork\CompletedWorkFactService;
use App\Services\Logging\LoggingService;
use App\Services\Workflow\WorkflowGuardService;
use DomainException;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ActingTestSchema;
use Tests\TestCase;

class ConstructionJournalContractCoverageTest extends TestCase
{
    use ActingTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpActingSchema();
    }

    public function test_auto_attach_flag_creates_contract_coverage_but_schedule_missing_blocks_acting(): void
    {
        [$organization, $user, $contract, $project, $estimate, $estimateItem] = $this->createJournalFixture();
        $journal = $this->createJournal($organization, $project, $contract, $user);

        app(ConstructionJournalService::class)->createEntry($journal, [
            'estimate_id' => $estimate->id,
            'entry_date' => '2026-04-28',
            'work_description' => 'Бетонирование',
            'status' => 'approved',
            'work_volumes' => [
                [
                    'estimate_item_id' => $estimateItem->id,
                    'quantity' => 15,
                    'auto_attach_contract_coverage' => true,
                ],
            ],
        ], $user);

        $this->assertDatabaseHas('contract_estimate_items', [
            'contract_id' => $contract->id,
            'estimate_item_id' => $estimateItem->id,
            'amount' => 50000,
        ]);

        $this->assertDatabaseHas('completed_works', [
            'contract_id' => $contract->id,
            'contractor_id' => $contract->contractor_id,
            'estimate_item_id' => $estimateItem->id,
            'status' => 'confirmed',
        ]);

        $this->withoutMiddleware();
        $this->allowPermissions();
        $this->actingAs($user, 'api_admin')
            ->postJson('/api/v1/admin/act-reports/preview', [
                'contract_id' => $contract->id,
                'period_start' => '2026-04-01',
                'period_end' => '2026-04-30',
            ])
            ->assertOk()
            ->assertJsonCount(0, 'data.available_works')
            ->assertJsonCount(1, 'data.blocked_works')
            ->assertJsonPath('data.blocked_works.0.blockers.0.code', 'schedule_missing');
    }

    public function test_without_auto_attach_flag_uncovered_estimate_item_does_not_create_coverage_or_contract_fact(): void
    {
        [$organization, $user, $contract, $project, $estimate, $estimateItem] = $this->createJournalFixture();
        $journal = $this->createJournal($organization, $project, $contract, $user);

        app(ConstructionJournalService::class)->createEntry($journal, [
            'estimate_id' => $estimate->id,
            'entry_date' => '2026-04-28',
            'work_description' => 'Бетонирование',
            'status' => 'approved',
            'work_volumes' => [
                [
                    'estimate_item_id' => $estimateItem->id,
                    'quantity' => 15,
                ],
            ],
        ], $user);

        $this->assertDatabaseCount('contract_estimate_items', 0);

        $work = CompletedWork::query()->where('estimate_item_id', $estimateItem->id)->firstOrFail();

        $this->assertNull($work->contract_id);
        $this->assertNull($work->contractor_id);
    }

    public function test_multiple_coverages_without_journal_contract_keep_fact_without_contract(): void
    {
        [$organization, $user, $contract, $project, $estimate, $estimateItem] = $this->createJournalFixture();
        $secondContract = Contract::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contract->contractor_id,
            'number' => 'COV-2',
            'date' => '2026-04-01',
            'subject' => 'Works',
            'total_amount' => 100000,
            'status' => 'active',
        ]);
        foreach ([$contract, $secondContract] as $coveredContract) {
            ContractEstimateItem::create([
                'contract_id' => $coveredContract->id,
                'estimate_id' => $estimate->id,
                'estimate_item_id' => $estimateItem->id,
                'quantity' => 50,
                'amount' => 50000,
            ]);
        }
        $journal = $this->createJournal($organization, $project, null, $user);

        app(ConstructionJournalService::class)->createEntry($journal, [
            'estimate_id' => $estimate->id,
            'entry_date' => '2026-04-28',
            'work_description' => 'Бетонирование',
            'status' => 'approved',
            'work_volumes' => [
                [
                    'estimate_item_id' => $estimateItem->id,
                    'quantity' => 15,
                ],
            ],
        ], $user);

        $work = CompletedWork::query()->where('estimate_item_id', $estimateItem->id)->firstOrFail();

        $this->assertNull($work->contract_id);
        $this->assertNull($work->contractor_id);
    }

    public function test_full_coverage_sync_updates_existing_approved_journal_work_contract(): void
    {
        [$organization, $user, $contract, $project, $estimate, $estimateItem] = $this->createJournalFixture();
        $journal = $this->createJournal($organization, $project, $contract, $user);

        app(ConstructionJournalService::class)->createEntry($journal, [
            'estimate_id' => $estimate->id,
            'entry_date' => '2026-04-28',
            'work_description' => 'Р‘РµС‚РѕРЅРёСЂРѕРІР°РЅРёРµ',
            'status' => 'approved',
            'work_volumes' => [
                [
                    'estimate_item_id' => $estimateItem->id,
                    'quantity' => 15,
                ],
            ],
        ], $user);

        $work = CompletedWork::query()->where('estimate_item_id', $estimateItem->id)->firstOrFail();
        $this->assertNull($work->contract_id);

        app(EstimateCoverageService::class)->syncCoverageItems($contract, $estimate, [$estimateItem->id]);

        $this->assertDatabaseHas('completed_works', [
            'id' => $work->id,
            'contract_id' => $contract->id,
            'contractor_id' => $contract->contractor_id,
            'total_amount' => 15000,
        ]);
    }

    public function test_completed_work_keeps_stable_journal_volume_link_when_work_volumes_are_reordered(): void
    {
        [$organization, $user, $contract, $project, $estimate, $estimateItem] = $this->createJournalFixture();
        $journal = $this->createJournal($organization, $project, $contract, $user);

        $entry = app(ConstructionJournalService::class)->createEntry($journal, [
            'estimate_id' => $estimate->id,
            'entry_date' => '2026-04-28',
            'work_description' => 'Бетонирование',
            'status' => 'approved',
            'work_volumes' => [
                [
                    'estimate_item_id' => $estimateItem->id,
                    'quantity' => 15,
                    'auto_attach_contract_coverage' => true,
                ],
            ],
        ], $user);

        $originalVolume = $entry->workVolumes()->firstOrFail();
        $originalWork = CompletedWork::query()
            ->where('journal_work_volume_id', $originalVolume->id)
            ->firstOrFail();

        app(ConstructionJournalService::class)->updateEntry($entry, [
            'work_volumes' => [
                [
                    'estimate_item_id' => $estimateItem->id,
                    'quantity' => 4,
                ],
                [
                    'id' => $originalVolume->id,
                    'estimate_item_id' => $estimateItem->id,
                    'quantity' => 15,
                ],
            ],
        ]);

        $this->assertDatabaseHas('completed_works', [
            'id' => $originalWork->id,
            'journal_work_volume_id' => $originalVolume->id,
            'completed_quantity' => 15,
        ]);

        $this->assertSame(2, CompletedWork::query()->where('journal_entry_id', $entry->id)->count());
    }

    public function test_contract_item_resource_uses_contract_quantity_as_planned_quantity(): void
    {
        [$organization, $user, $contract, $project, $estimate, $estimateItem] = $this->createJournalFixture();

        $estimateItem->forceFill([
            'quantity' => 0,
            'quantity_total' => 0,
        ])->save();

        $link = ContractEstimateItem::create([
            'contract_id' => $contract->id,
            'estimate_id' => $estimate->id,
            'estimate_item_id' => $estimateItem->id,
            'quantity' => 100,
            'amount' => 100000,
        ]);

        CompletedWork::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contract_id' => $contract->id,
            'estimate_item_id' => $estimateItem->id,
            'work_origin_type' => CompletedWork::ORIGIN_JOURNAL,
            'planning_status' => CompletedWork::PLANNING_PLANNED,
            'quantity' => 85,
            'completed_quantity' => 85,
            'price' => 1000,
            'total_amount' => 85000,
            'completion_date' => '2026-04-28',
            'status' => 'confirmed',
        ]);

        $payload = (new ContractEstimateItemResource($link->fresh('estimateItem')))->toArray(request());

        $this->assertSame(100.0, $payload['item']['planned_quantity']);
        $this->assertSame(85.0, $payload['item']['actual_quantity']);
        $this->assertSame(85.0, $payload['item']['fact_progress_percent']);
        $this->assertSame([], $payload['item']['blockers']);
    }

    public function test_contract_item_resource_reports_blocker_when_fact_has_no_plan(): void
    {
        [$organization, $user, $contract, $project, $estimate, $estimateItem] = $this->createJournalFixture();

        $estimateItem->forceFill([
            'quantity' => 0,
            'quantity_total' => 0,
            'unit_price' => 0,
            'total_amount' => 0,
        ])->save();

        $link = ContractEstimateItem::create([
            'contract_id' => $contract->id,
            'estimate_id' => $estimate->id,
            'estimate_item_id' => $estimateItem->id,
            'quantity' => 0,
            'amount' => 0,
        ]);

        CompletedWork::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contract_id' => $contract->id,
            'estimate_item_id' => $estimateItem->id,
            'work_origin_type' => CompletedWork::ORIGIN_JOURNAL,
            'planning_status' => CompletedWork::PLANNING_PLANNED,
            'quantity' => 85,
            'completed_quantity' => 85,
            'price' => 1000,
            'total_amount' => 85000,
            'completion_date' => '2026-04-28',
            'status' => 'confirmed',
        ]);

        $payload = (new ContractEstimateItemResource($link->fresh('estimateItem')))->toArray(request());

        $this->assertSame(0.0, $payload['item']['planned_quantity']);
        $this->assertSame(85.0, $payload['item']['actual_quantity']);
        $this->assertSame('blocked', $payload['item']['workflow_state']);
        $this->assertSame('missing_planned_quantity', $payload['item']['blockers'][0]['code']);
    }

    public function test_approval_without_estimate_item_is_blocked(): void
    {
        [$organization, $user, $contract, $project, $estimate] = $this->createJournalFixture();
        $approver = User::factory()->create(['current_organization_id' => $organization->id]);
        $journal = $this->createJournal($organization, $project, $contract, $user);

        $this->allowPermissions();

        $entry = app(ConstructionJournalService::class)->createEntry($journal, [
            'estimate_id' => $estimate->id,
            'entry_date' => '2026-04-28',
            'work_description' => 'Вне сметы',
            'status' => 'submitted',
            'work_volumes' => [
                [
                    'quantity' => 5,
                ],
            ],
        ], $user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Для записи журнала нужно выбрать позицию сметы');

        app(JournalApprovalService::class)->approve($entry, $approver);
    }

    public function test_schedule_missing_override_writes_audit_and_allows_approval(): void
    {
        [$organization, $user, $contract, $project, $estimate, $estimateItem] = $this->createJournalFixture();
        $approver = User::factory()->create(['current_organization_id' => $organization->id]);
        $journal = $this->createJournal($organization, $project, $contract, $user);

        $this->allowPermissions();
        Notification::fake();

        $this->mock(LoggingService::class, function ($mock): void {
            $mock->shouldReceive('audit')
                ->once()
                ->with('workflow.override.used', \Mockery::on(
                    fn (array $context): bool => $context['target'] === 'schedule_missing'
                        && $context['reason'] === 'Факт принят без задачи графика, привяжем после уточнения календаря'
                        && $context['operation'] === 'journal_approve'
                ));
        });

        $entry = app(ConstructionJournalService::class)->createEntry($journal, [
            'estimate_id' => $estimate->id,
            'entry_date' => '2026-04-28',
            'work_description' => 'Бетонирование',
            'status' => 'submitted',
            'work_volumes' => [
                [
                    'estimate_item_id' => $estimateItem->id,
                    'quantity' => 15,
                    'auto_attach_contract_coverage' => true,
                ],
            ],
        ], $user);

        $approved = app(JournalApprovalService::class)->approve($entry, $approver, [
            'enabled' => true,
            'target' => 'schedule_missing',
            'reason' => 'Факт принят без задачи графика, привяжем после уточнения календаря',
        ]);

        $this->assertSame('approved', $approved->status->value);
    }

    public function test_journal_entry_without_explicit_task_is_ready_when_estimate_item_has_single_schedule_task(): void
    {
        [$organization, $user, $contract, $project, $estimate, $estimateItem] = $this->createJournalFixture();
        $journal = $this->createJournal($organization, $project, $contract, $user);
        $this->coverEstimateItem($contract, $estimate, $estimateItem);
        $this->createScheduleTaskForEstimateItem($organization, $project, $estimateItem, 3);

        $entry = app(ConstructionJournalService::class)->createEntry($journal, [
            'estimate_id' => $estimate->id,
            'entry_date' => '2026-04-29',
            'work_description' => 'РўРµСЃС‚РѕРІР°СЏ СЂР°Р±РѕС‚Р°',
            'status' => 'submitted',
            'work_volumes' => [
                [
                    'estimate_item_id' => $estimateItem->id,
                    'quantity' => 1,
                ],
            ],
        ], $user);

        $blockers = app(WorkflowGuardService::class)->journalEntryBlockers($entry);

        $this->assertSame([], array_column($blockers, 'code'));
    }

    public function test_approval_autolinks_unique_estimate_schedule_task_and_updates_progress(): void
    {
        [$organization, $user, $contract, $project, $estimate, $estimateItem] = $this->createJournalFixture();
        $approver = User::factory()->create(['current_organization_id' => $organization->id]);
        $journal = $this->createJournal($organization, $project, $contract, $user);
        $this->coverEstimateItem($contract, $estimate, $estimateItem);
        $task = $this->createScheduleTaskForEstimateItem($organization, $project, $estimateItem, 3);

        $this->allowPermissions();
        Notification::fake();

        $entry = app(ConstructionJournalService::class)->createEntry($journal, [
            'estimate_id' => $estimate->id,
            'entry_date' => '2026-04-29',
            'work_description' => 'РўРµСЃС‚РѕРІР°СЏ СЂР°Р±РѕС‚Р°',
            'status' => 'submitted',
            'work_volumes' => [
                [
                    'estimate_item_id' => $estimateItem->id,
                    'quantity' => 1,
                ],
            ],
        ], $user);

        $approved = app(JournalApprovalService::class)->approve($entry, $approver);
        $work = CompletedWork::query()
            ->where('journal_entry_id', $entry->id)
            ->where('estimate_item_id', $estimateItem->id)
            ->firstOrFail();

        $this->assertSame('approved', $approved->status->value);
        $this->assertSame($task->id, $approved->schedule_task_id);
        $this->assertSame($task->id, $work->schedule_task_id);
        $this->assertSame(1.0, (float) $task->fresh()->completed_quantity);
        $this->assertSame(33.33, (float) $task->fresh()->progress_percent);
    }

    public function test_repair_backfills_existing_journal_completed_work_schedule_links(): void
    {
        [$organization, $user, $contract, $project, $estimate, $estimateItem] = $this->createJournalFixture();
        $journal = $this->createJournal($organization, $project, $contract, $user);
        $this->coverEstimateItem($contract, $estimate, $estimateItem);
        $task = $this->createScheduleTaskForEstimateItem($organization, $project, $estimateItem, 3);

        $entry = \App\Models\ConstructionJournalEntry::create([
            'journal_id' => $journal->id,
            'estimate_id' => $estimate->id,
            'entry_date' => '2026-04-29',
            'entry_number' => 1,
            'work_description' => 'РўРµСЃС‚РѕРІР°СЏ СЂР°Р±РѕС‚Р°',
            'status' => 'approved',
            'created_by_user_id' => $user->id,
        ]);
        $volume = JournalWorkVolume::create([
            'journal_entry_id' => $entry->id,
            'estimate_item_id' => $estimateItem->id,
            'quantity' => 1,
        ]);
        CompletedWork::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contract_id' => $contract->id,
            'estimate_item_id' => $estimateItem->id,
            'journal_entry_id' => $entry->id,
            'journal_work_volume_id' => $volume->id,
            'work_origin_type' => CompletedWork::ORIGIN_JOURNAL,
            'planning_status' => CompletedWork::PLANNING_REQUIRES_SCHEDULE,
            'quantity' => 1,
            'completed_quantity' => 1,
            'completion_date' => '2026-04-29',
            'status' => 'confirmed',
        ]);

        $repaired = app(CompletedWorkFactService::class)->repairJournalScheduleLinks($organization->id);
        $work = CompletedWork::query()->where('journal_work_volume_id', $volume->id)->firstOrFail();

        $this->assertSame(1, $repaired);
        $this->assertSame($task->id, $entry->fresh()->schedule_task_id);
        $this->assertSame($task->id, $work->schedule_task_id);
        $this->assertSame(CompletedWork::PLANNING_PLANNED, $work->planning_status);
        $this->assertSame(1.0, (float) $task->fresh()->completed_quantity);
        $this->assertSame(33.33, (float) $task->fresh()->progress_percent);
    }

    private function createJournalFixture(): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $contractor = Contractor::create([
            'organization_id' => $organization->id,
            'name' => 'МТМ СТРОЙ',
        ]);
        $contract = Contract::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => 'Тестовый-1',
            'date' => '2026-04-01',
            'subject' => 'Works',
            'total_amount' => 100000,
            'status' => 'active',
        ]);
        $estimate = Estimate::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'name' => 'Estimate',
            'status' => 'approved',
            'total_amount' => 50000,
        ]);
        $estimateItem = EstimateItem::create([
            'estimate_id' => $estimate->id,
            'position_number' => '5',
            'item_type' => EstimatePositionItemType::WORK->value,
            'name' => 'Бетонирование',
            'quantity' => 50,
            'quantity_total' => 50,
            'unit_price' => 1000,
            'total_amount' => 50000,
        ]);

        return [$organization, $user, $contract, $project, $estimate, $estimateItem];
    }

    private function createJournal(Organization $organization, Project $project, ?Contract $contract, User $user): ConstructionJournal
    {
        return ConstructionJournal::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contract_id' => $contract?->id,
            'name' => 'Журнал',
            'journal_number' => '1',
            'start_date' => '2026-04-01',
            'status' => 'active',
            'created_by_user_id' => $user->id,
        ]);
    }

    private function coverEstimateItem(Contract $contract, Estimate $estimate, EstimateItem $estimateItem): ContractEstimateItem
    {
        return ContractEstimateItem::create([
            'contract_id' => $contract->id,
            'estimate_id' => $estimate->id,
            'estimate_item_id' => $estimateItem->id,
            'quantity' => 50,
            'amount' => 50000,
        ]);
    }

    private function createScheduleTaskForEstimateItem(
        Organization $organization,
        Project $project,
        EstimateItem $estimateItem,
        float $quantity
    ): ScheduleTask {
        $schedule = ProjectSchedule::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'name' => 'Р“СЂР°С„РёРє СЂР°Р±РѕС‚',
            'status' => 'active',
        ]);

        return ScheduleTask::create([
            'organization_id' => $organization->id,
            'schedule_id' => $schedule->id,
            'estimate_item_id' => $estimateItem->id,
            'name' => $estimateItem->name,
            'task_type' => 'task',
            'quantity' => $quantity,
            'completed_quantity' => 0,
            'progress_percent' => 0,
            'status' => 'not_started',
            'priority' => 'normal',
            'level' => 0,
            'sort_order' => 1,
        ]);
    }

    private function allowPermissions(bool $allowed = true): void
    {
        $this->mock(AuthorizationService::class, function ($mock) use ($allowed): void {
            $mock->shouldReceive('can')->andReturn($allowed);
        });
    }
}
