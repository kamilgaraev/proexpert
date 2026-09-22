<?php

declare(strict_types=1);

namespace Tests\Feature\CompletedWork;

use App\Enums\EstimatePositionItemType;
use App\Models\CompletedWork;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class JournalFactIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeating_journal_sync_keeps_one_fact_per_volume_and_preserves_distinct_volumes(): void
    {
        [$entry, $volumeA, $volumeB] = $this->fixture();
        $service = app(CompletedWorkFactService::class);

        $service->syncFromJournalEntry($entry);
        $service->syncFromJournalEntry($entry->fresh());

        self::assertSame(2, CompletedWork::query()->where('journal_entry_id', $entry->id)->count());
        self::assertSame(1, CompletedWork::query()->where('journal_work_volume_id', $volumeA->id)->count());
        self::assertSame(1, CompletedWork::query()->where('journal_work_volume_id', $volumeB->id)->count());
    }

    public function test_acted_journal_fact_allows_identical_retry_but_not_changed_source_or_deletion(): void
    {
        [$entry, $volume] = $this->fixture();
        $estimate = $volume->estimateItem->estimate;
        $snapshot = [
            'schema_version' => 2,
            'rates' => ['vat_rate' => '0'],
            'sections' => [],
            'unsectioned_items' => [[
                'id' => $volume->estimate_item_id, 'name' => 'Тестовая работа',
                'position_number' => '1', 'quantity' => '10', 'quantity_total' => '10',
                'unit_price' => '100', 'total_amount' => '1000', 'children' => [],
            ]],
        ];
        $version = \App\Models\EstimateVersion::query()->create([
            'estimate_id' => $estimate->id, 'organization_id' => $estimate->organization_id,
            'created_by_user_id' => $entry->created_by_user_id,
            'approved_by_user_id' => $entry->created_by_user_id, 'approved_at' => now(),
            'version_number' => 1, 'label' => 'Утверждённая версия', 'snapshot_type' => 'approval',
            'estimate_status' => 'approved', 'status' => 'approved', 'snapshot' => $snapshot,
            'snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'total_amount' => 1000, 'total_amount_with_vat' => 1000, 'total_direct_costs' => 0,
        ]);
        $estimate->forceFill(['current_version_id' => $version->id])->saveQuietly();
        $schedule = ProjectSchedule::query()->create([
            'organization_id' => $entry->journal->organization_id,
            'project_id' => $entry->journal->project_id,
            'created_by_user_id' => $entry->created_by_user_id,
            'planned_start_date' => '2026-09-01',
            'planned_end_date' => '2026-09-30',
            'name' => 'Журнальные работы',
        ]);
        ScheduleTask::query()->create([
            'organization_id' => $schedule->organization_id,
            'schedule_id' => $schedule->id,
            'estimate_item_id' => $volume->estimate_item_id,
            'created_by_user_id' => $entry->created_by_user_id,
            'planned_start_date' => '2026-09-01',
            'planned_end_date' => '2026-09-30',
            'planned_duration_days' => 30,
            'name' => 'Работы по журналу',
            'quantity' => 10,
            'completed_quantity' => 0,
        ]);
        $service = app(CompletedWorkFactService::class);
        $service->syncFromJournalEntry($entry);
        $work = CompletedWork::query()->where('journal_work_volume_id', $volume->id)->firstOrFail();
        app(\App\Services\Acting\ActingActWizardService::class)->createFromWizard($work->organization_id, [
            'contract_id' => $work->contract_id, 'act_document_number' => 'JOURNAL-PROTECTED',
            'act_date' => '2026-09-20', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'selected_works' => [['completed_work_id' => $work->id, 'quantity' => '2']],
        ], $entry->created_by_user_id, false);
        $service->syncFromJournalEntry($entry->fresh());

        foreach ([
            function () use ($volume, $entry, $service): void {
                DB::transaction(function () use ($volume, $entry, $service): void {
                    $volume->update(['quantity' => 1]);
                    $service->syncFromJournalEntry($entry->fresh());
                });
            },
            fn () => $service->deleteJournalEntryFacts($entry->fresh()),
        ] as $operation) {
            try {
                $operation();
                self::fail('Acted journal facts must preserve their source and volume.');
            } catch (\App\Exceptions\BusinessLogicException $exception) {
                self::assertSame(422, $exception->getCode());
            }
            self::assertSame('3.0000', $work->fresh()->completed_quantity);
            self::assertSame(3.0, (float) $volume->fresh()->quantity);
            self::assertSame(2, CompletedWork::query()->where('journal_entry_id', $entry->id)->count());
        }
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($user->id, ['is_active' => true, 'is_owner' => true, 'project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]);
        $contractor = Contractor::create(['organization_id' => $organization->id, 'name' => 'Тестовый подрядчик']);
        $contract = Contract::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => 'IDEMP-1',
            'contract_side_type' => 'subcontract',
            'date' => '2026-09-20',
            'subject' => 'Работы',
            'total_amount' => 100000,
            'status' => 'active',
        ]);
        $estimate = Estimate::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'name' => 'Смета',
            'number' => 'IDEMP-EST',
            'estimate_date' => '2026-09-20',
            'status' => 'approved',
            'total_amount' => 10000,
        ]);
        $unitId = DB::table('measurement_units')->where('organization_id', $organization->id)->where('short_name', 'м³')->value('id');
        $workTypeId = DB::table('work_types')->insertGetId([
            'organization_id' => $organization->id,
            'name' => 'Тестовая работа',
            'measurement_unit_id' => $unitId,
            'category' => 'construction',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $item = EstimateItem::create([
            'estimate_id' => $estimate->id,
            'position_number' => '1',
            'item_type' => EstimatePositionItemType::WORK->value,
            'name' => 'Тестовая работа',
            'work_type_id' => $workTypeId,
            'measurement_unit_id' => $unitId,
            'quantity' => 10,
            'quantity_total' => 10,
            'unit_price' => 100,
            'total_amount' => 1000,
        ]);
        ContractEstimateItem::create([
            'contract_id' => $contract->id,
            'estimate_id' => $estimate->id,
            'estimate_item_id' => $item->id,
            'quantity' => 10,
            'amount' => 1000,
        ]);
        $journal = ConstructionJournal::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contract_id' => $contract->id,
            'name' => 'Журнал',
            'journal_number' => 'IDEMP-J',
            'start_date' => '2026-09-01',
            'status' => 'active',
            'created_by_user_id' => $user->id,
        ]);
        $entry = ConstructionJournalEntry::create([
            'journal_id' => $journal->id,
            'estimate_id' => $estimate->id,
            'entry_date' => '2026-09-20',
            'entry_number' => 1,
            'work_description' => 'Работы',
            'status' => 'approved',
            'created_by_user_id' => $user->id,
        ]);
        $volumeA = JournalWorkVolume::create(['journal_entry_id' => $entry->id, 'estimate_item_id' => $item->id, 'quantity' => 3, 'work_type_id' => $workTypeId]);
        $volumeB = JournalWorkVolume::create(['journal_entry_id' => $entry->id, 'estimate_item_id' => $item->id, 'quantity' => 4, 'work_type_id' => $workTypeId]);

        return [$entry, $volumeA, $volumeB];
    }
}
