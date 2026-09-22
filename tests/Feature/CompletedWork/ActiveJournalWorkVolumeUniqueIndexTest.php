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
use App\Services\CompletedWork\ActiveJournalWorkVolumeUniqueness;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ActiveJournalWorkVolumeUniqueIndexTest extends TestCase
{
    public function test_migration_and_command_skip_index_when_duplicates_exist_without_changing_rows(): void
    {
        $uniqueness = app(ActiveJournalWorkVolumeUniqueness::class);
        DB::statement('DROP INDEX IF EXISTS '.ActiveJournalWorkVolumeUniqueness::INDEX_NAME);
        self::assertFalse($uniqueness->indexExists());

        [$user, $organization, $project, $volumes] = $this->fixture(1);
        $volume = $volumes[0];
        $first = $this->work($organization->id, $project->id, $user->id, $volume->id, 5);
        $second = $this->work($organization->id, $project->id, $user->id, $volume->id, 7);
        $before = CompletedWork::query()->where('journal_work_volume_id', $volume->id)->count();
        self::assertSame(2, $before);

        $migration = require database_path('migrations/2026_09_20_020000_add_unique_active_journal_work_volume_fact.php');
        $migration->up();

        self::assertFalse($uniqueness->indexExists());
        self::assertSame($before, CompletedWork::query()->where('journal_work_volume_id', $volume->id)->count());
        self::assertSame('5.0000', (string) $first->fresh()->quantity);
        self::assertSame('7.0000', (string) $second->fresh()->quantity);

        $this->artisan('completed-works:ensure-active-journal-volume-unique-index')
            ->expectsOutputToContain('Индекс не создан')
            ->assertFailed();

        self::assertFalse($uniqueness->indexExists());
        self::assertSame($before, CompletedWork::query()->where('journal_work_volume_id', $volume->id)->count());
        self::assertSame('5.0000', (string) $first->fresh()->quantity);
        self::assertSame('7.0000', (string) $second->fresh()->quantity);
    }

    public function test_migration_and_command_create_index_when_no_duplicates(): void
    {
        $uniqueness = app(ActiveJournalWorkVolumeUniqueness::class);
        DB::statement('DROP INDEX IF EXISTS '.ActiveJournalWorkVolumeUniqueness::INDEX_NAME);
        self::assertFalse($uniqueness->indexExists());

        [$user, $organization, $project, $volumes] = $this->fixture(2);
        $this->work($organization->id, $project->id, $user->id, $volumes[0]->id, 3);
        $this->work($organization->id, $project->id, $user->id, $volumes[1]->id, 4);

        $migration = require database_path('migrations/2026_09_20_020000_add_unique_active_journal_work_volume_fact.php');
        $migration->up();
        self::assertTrue($uniqueness->indexExists());

        $this->artisan('completed-works:ensure-active-journal-volume-unique-index')
            ->assertSuccessful();
        self::assertTrue($uniqueness->indexExists());
    }

    /**
     * @return array{0: User, 1: Organization, 2: Project, 3: list<JournalWorkVolume>}
     */
    private function fixture(int $volumeCount): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($user->id, [
            'is_active' => true,
            'is_owner' => true,
            'project_access_mode' => 'all_projects',
        ]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'is_archived' => false,
        ]);
        $contractor = Contractor::create([
            'organization_id' => $organization->id,
            'name' => 'Подрядчик индекса',
        ]);
        $contract = Contract::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => 'IDX-1',
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
            'number' => 'IDX-EST',
            'estimate_date' => '2026-09-20',
            'status' => 'approved',
            'total_amount' => 10000,
        ]);
        $unitId = DB::table('measurement_units')
            ->where('organization_id', $organization->id)
            ->where('short_name', 'м³')
            ->value('id');
        $workTypeId = DB::table('work_types')->insertGetId([
            'organization_id' => $organization->id,
            'name' => 'Работа индекса',
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
            'name' => 'Работа индекса',
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
            'journal_number' => 'IDX-J',
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

        $volumes = [];
        for ($i = 0; $i < $volumeCount; $i++) {
            $volumes[] = JournalWorkVolume::create([
                'journal_entry_id' => $entry->id,
                'estimate_item_id' => $item->id,
                'quantity' => 1 + $i,
                'work_type_id' => $workTypeId,
            ]);
        }

        return [$user, $organization, $project, $volumes];
    }

    private function work(
        int $organizationId,
        int $projectId,
        int $userId,
        int $journalWorkVolumeId,
        int $quantity,
    ): CompletedWork {
        $workTypeId = DB::table('work_types')->insertGetId([
            'organization_id' => $organizationId,
            'name' => 'Факт индекса '.$journalWorkVolumeId.' '.uniqid(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return CompletedWork::create([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'work_type_id' => $workTypeId,
            'user_id' => $userId,
            'journal_work_volume_id' => $journalWorkVolumeId,
            'quantity' => $quantity,
            'completed_quantity' => $quantity,
            'completion_date' => '2026-09-20',
            'status' => CompletedWork::STATUS_DRAFT,
            'work_origin_type' => CompletedWork::ORIGIN_JOURNAL,
        ]);
    }
}
