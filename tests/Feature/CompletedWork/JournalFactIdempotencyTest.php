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
