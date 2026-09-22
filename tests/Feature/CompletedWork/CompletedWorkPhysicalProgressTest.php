<?php

declare(strict_types=1);

namespace Tests\Feature\CompletedWork;

use App\Enums\EstimatePositionItemType;
use App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceExecution;
use App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceQuery;
use App\BusinessModules\Features\ContractManagement\Services\ContractEstimateOperationalProgress;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\ContractEstimateItem;
use App\Models\Contractor;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\JournalWorkVolume;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use App\BusinessModules\Features\BudgetEstimates\Services\Integration\JournalEstimateIntegrationService;
use App\Services\Schedule\ScheduleTaskCompletedWorkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

final class CompletedWorkPhysicalProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_null_completed_quantity_uses_quantity_without_rewriting_history(): void
    {
        [, $item] = $this->estimateFixture();
        $work = $this->work($item, 100, null);

        self::assertSame(100.0, $item->fresh()->getActualVolume());
        self::assertNull($work->fresh()->completed_quantity);
    }

    public function test_completed_quantity_is_the_physical_progress_quantity(): void
    {
        [, $item] = $this->estimateFixture();
        $work = $this->work($item, 100, 10);

        self::assertSame(10.0, $item->fresh()->getActualVolume());
        self::assertSame(100.0, (float) $work->fresh()->quantity);
        self::assertSame(10.0, (float) $work->fresh()->completed_quantity);
    }

    public function test_schedule_progress_excludes_resource_facts(): void
    {
        [$journal, $item, $user, $project, $estimate] = $this->estimateFixture();
        $schedule = ProjectSchedule::query()->create([
            'project_id' => $project->id,
            'organization_id' => $project->organization_id,
            'created_by_user_id' => $user->id,
            'name' => 'Physical schedule',
            'planned_start_date' => '2026-09-01',
            'planned_end_date' => '2026-09-30',
        ]);
        $task = ScheduleTask::query()->create([
            'schedule_id' => $schedule->id,
            'organization_id' => $project->organization_id,
            'created_by_user_id' => $user->id,
            'name' => 'Physical task',
            'planned_start_date' => '2026-09-01',
            'planned_end_date' => '2026-09-30',
            'planned_duration_days' => 30,
            'quantity' => 100,
            'completed_quantity' => 0,
        ]);
        $this->work($item, 10, 10, ['schedule_task_id' => $task->id, 'completion_date' => '2026-09-20']);
        $this->work($item, 500, 500, ['schedule_task_id' => $task->id,
            'completion_date' => '2026-09-01', 'additional_info' => ['fact_kind' => 'material']]);

        app(ScheduleTaskCompletedWorkService::class)->syncCompletedQuantity($task->fresh());

        self::assertSame(10.0, (float) $task->fresh()->completed_quantity);
        $entry = ConstructionJournalEntry::query()->create([
            'journal_id' => $journal->id, 'estimate_id' => $estimate->id,
            'schedule_task_id' => $task->id, 'entry_date' => '2026-09-20', 'entry_number' => 1,
            'work_description' => 'Проверка фактических дат', 'status' => 'approved',
            'created_by_user_id' => $user->id,
        ]);
        $actual = app(\App\BusinessModules\Features\BudgetEstimates\Services\Integration\JournalScheduleIntegrationService::class)
            ->updateTaskProgressFromEntry($entry);
        self::assertSame('2026-09-20', $actual->actual_start_date->toDateString());
        self::assertNull($actual->actual_end_date);
        self::assertSame(10.0, (float) $actual->completed_quantity);
    }

    public function test_journal_estimate_integration_excludes_resource_fact_and_uses_journal_once(): void
    {
        [$journal, $item, $user, , $estimate] = $this->estimateFixture();
        $entry = ConstructionJournalEntry::query()->create([
            'journal_id' => $journal->id,
            'estimate_id' => $estimate->id,
            'entry_date' => '2026-09-20',
            'entry_number' => 1,
            'work_description' => 'Journal volume',
            'status' => 'approved',
            'created_by_user_id' => $user->id,
        ]);
        $volume = JournalWorkVolume::query()->create([
            'journal_entry_id' => $entry->id,
            'estimate_item_id' => $item->id,
            'quantity' => 10,
        ]);
        $this->work($item, 10, 10, ['journal_entry_id' => $entry->id, 'journal_work_volume_id' => $volume->id]);
        $this->work($item, 50, 50, ['additional_info' => ['fact_kind' => 'equipment']]);

        $row = app(JournalEstimateIntegrationService::class)->getActualVsPlannedVolumes($estimate->fresh())[0];

        self::assertSame(10.0, $row['actual_volume']);
    }

    public function test_contract_operational_progress_excludes_resource_facts(): void
    {
        [, $item, $user, $project, $estimate] = $this->estimateFixture();
        $contractor = Contractor::query()->create([
            'organization_id' => $project->organization_id,
            'name' => 'Progress contractor',
        ]);
        $contract = Contract::query()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => 'PROGRESS-1',
            'date' => '2026-09-20',
            'subject' => 'Physical progress',
            'total_amount' => 10000,
            'status' => 'active',
        ]);
        $link = ContractEstimateItem::query()->create([
            'contract_id' => $contract->id,
            'estimate_id' => $estimate->id,
            'estimate_item_id' => $item->id,
            'quantity' => 100,
            'amount' => 10000,
        ]);
        $this->work($item, 100, 10, ['contract_id' => $contract->id]);
        $this->work($item, 50, 50, [
            'contract_id' => $contract->id,
            'additional_info' => ['fact_kind' => 'labor'],
        ]);

        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturnUsing(
            static fn ($actor, string $permission): bool => $permission === 'contracts.completed_works.view',
        );
        $projects = Mockery::mock(UserProjectAccessService::class);
        $projects->shouldReceive('queryAccessibleProjects')->andReturn(Project::query()->whereKey($project->id));

        $service = new ContractEstimateOperationalProgress(
            $authorization,
            $projects,
            app(EstimateFinanceExecution::class),
            app(EstimateFinanceQuery::class),
        );
        $service->prepare($contract, collect([$link]), $user);

        self::assertSame(10.0, $link->getRelation('operationalProgress')['actual_quantity']);
        $legacy = $this->work($item, 3, null, ['contract_id' => $contract->id]);
        self::assertSame([$legacy->id], app(\App\Services\Acting\CompletedWorkActEligibilityService::class)
            ->query($contract->id, '2026-09-01', '2026-09-30')->pluck('id')->all());
    }

    public function test_journal_volume_and_derived_fact_are_counted_once(): void
    {
        [$journal, $item, $user] = $this->estimateFixture();
        $entry = ConstructionJournalEntry::query()->create([
            'journal_id' => $journal->id,
            'estimate_id' => $item->estimate_id,
            'entry_date' => '2026-09-20',
            'entry_number' => 1,
            'work_description' => 'Journal volume',
            'status' => 'approved',
            'created_by_user_id' => $user->id,
        ]);
        $volume = JournalWorkVolume::query()->create([
            'journal_entry_id' => $entry->id,
            'estimate_item_id' => $item->id,
            'quantity' => 10,
        ]);
        $this->work($item, 10, 10, ['journal_entry_id' => $entry->id, 'journal_work_volume_id' => $volume->id]);

        self::assertSame(10.0, $item->fresh()->getActualVolume());
    }

    public function test_material_equipment_labor_and_worker_facts_are_excluded_from_physical_progress(): void
    {
        [, $item] = $this->estimateFixture();
        foreach ([
            ['fact_kind' => 'material'],
            ['fact_kind' => 'equipment'],
            ['fact_kind' => 'worker'],
            ['fact_kind' => 'labor'],
        ] as $resource) {
            $this->work($item, 50, 50, array_filter([
                'additional_info' => ['fact_kind' => $resource['fact_kind']],
            ]));
        }

        self::assertSame(0.0, $item->fresh()->getActualVolume());
    }

    private function estimateFixture(): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $estimate = Estimate::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'name' => 'Physical estimate',
            'number' => 'PHYSICAL-1',
            'status' => 'approved',
            'type' => 'local',
            'version' => 1,
            'estimate_date' => '2026-09-20',
        ]);
        $item = EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'position_number' => '1',
            'item_type' => EstimatePositionItemType::WORK->value,
            'name' => 'Physical work',
            'quantity' => 100,
            'quantity_total' => 100,
        ]);
        $journal = ConstructionJournal::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'name' => 'Physical journal',
            'journal_number' => 'PHYSICAL-J',
            'start_date' => '2026-09-01',
            'status' => 'active',
            'created_by_user_id' => $user->id,
        ]);

        return [$journal, $item, $user, $project, $estimate];
    }

    private function work(EstimateItem $item, float $quantity, ?float $completedQuantity, array $extra = []): CompletedWork
    {
        return CompletedWork::query()->create(array_merge([
            'organization_id' => $item->estimate->organization_id,
            'project_id' => $item->estimate->project_id,
            'estimate_item_id' => $item->id,
            'quantity' => $quantity,
            'completed_quantity' => $completedQuantity,
            'completion_date' => '2026-09-20',
            'status' => CompletedWork::STATUS_CONFIRMED,
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
            'planning_status' => CompletedWork::PLANNING_PLANNED,
        ], $extra));
    }
}
