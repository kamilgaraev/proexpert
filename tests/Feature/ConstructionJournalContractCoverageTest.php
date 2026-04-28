<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\BusinessModules\Features\BudgetEstimates\Services\ConstructionJournalService;
use App\BusinessModules\Features\BudgetEstimates\Services\Integration\EstimateCoverageService;
use App\Enums\EstimatePositionItemType;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\CompletedWork;
use App\Models\ConstructionJournal;
use App\Models\Contract;
use App\Models\ContractEstimateItem;
use App\Models\Contractor;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
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

    public function test_auto_attach_flag_creates_contract_coverage_and_makes_journal_work_available_for_acting(): void
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
            ->assertJsonCount(1, 'data.available_works');
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

    private function allowPermissions(bool $allowed = true): void
    {
        $this->mock(AuthorizationService::class, function ($mock) use ($allowed): void {
            $mock->shouldReceive('can')->andReturn($allowed);
        });
    }
}
