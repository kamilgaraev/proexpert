<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Features\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Integration\JournalEstimateIntegrationService;
use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use App\Enums\EstimatePositionItemType;
use App\Models\CompletedWork;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\JournalWorkVolume;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class JournalEstimateIntegrationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_actual_vs_planned_volumes_are_loaded_with_batched_volume_queries(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $workType = WorkType::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Монтаж',
            'code' => 'WT-'.random_int(1000, 9999),
            'default_price' => 1000,
            'is_active' => true,
        ]);
        $estimate = Estimate::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'EST-PROGRESS',
            'name' => 'Смета с прогрессом',
            'type' => 'local',
            'status' => 'approved',
            'estimate_date' => '2026-06-01',
            'total_amount' => 60000,
            'total_amount_with_vat' => 60000,
        ]);

        $items = collect(range(1, 6))->map(fn (int $position): EstimateItem => EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'position_number' => (string) $position,
            'item_type' => EstimatePositionItemType::WORK->value,
            'name' => 'Работа '.$position,
            'work_type_id' => $workType->id,
            'quantity' => 10,
            'quantity_total' => 10,
            'unit_price' => 1000,
            'total_amount' => 10000,
        ]));

        $journal = ConstructionJournal::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'name' => 'Журнал работ',
            'journal_number' => '1',
            'start_date' => '2026-06-01',
            'status' => 'active',
            'created_by_user_id' => $user->id,
        ]);
        $entry = ConstructionJournalEntry::query()->create([
            'journal_id' => $journal->id,
            'estimate_id' => $estimate->id,
            'entry_date' => '2026-06-02',
            'entry_number' => 1,
            'work_description' => 'Выполненные работы',
            'status' => JournalEntryStatusEnum::APPROVED->value,
            'created_by_user_id' => $user->id,
        ]);

        JournalWorkVolume::query()->create([
            'journal_entry_id' => $entry->id,
            'estimate_item_id' => $items[0]->id,
            'quantity' => 2,
        ]);
        JournalWorkVolume::query()->create([
            'journal_entry_id' => $entry->id,
            'estimate_item_id' => $items[1]->id,
            'quantity' => 3,
        ]);
        CompletedWork::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'estimate_item_id' => $items[1]->id,
            'work_type_id' => $workType->id,
            'user_id' => $user->id,
            'quantity' => 4,
            'completed_quantity' => 4,
            'price' => 1000,
            'total_amount' => 4000,
            'completion_date' => '2026-06-02',
            'status' => 'confirmed',
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
            'planning_status' => CompletedWork::PLANNING_PLANNED,
        ]);

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $result = app(JournalEstimateIntegrationService::class)->getActualVsPlannedVolumes($estimate);

        self::assertCount(6, $result);
        self::assertSame(2.0, $result[0]['actual_volume']);
        self::assertSame(4.0, $result[1]['actual_volume']);
        self::assertSame(20.0, $result[0]['completion_percent']);
        self::assertSame(40.0, $result[1]['completion_percent']);
        self::assertLessThanOrEqual(8, count($queries));
    }
}
