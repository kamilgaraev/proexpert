<?php

declare(strict_types=1);

namespace Tests\Unit\Acting;

use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\Contractor;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Organization;
use App\Models\PerformanceActLine;
use App\Models\Project;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Services\Acting\ActingAvailabilityService;
use Tests\Support\ActingTestSchema;
use Tests\TestCase;

class ActingAvailabilityServiceTest extends TestCase
{
    use ActingTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpActingSchema();
    }

    public function test_available_works_exclude_already_acted_quantity(): void
    {
        [$contract, $project, $organization] = $this->createContract();

        $work = CompletedWork::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contract_id' => $contract->id,
            'journal_entry_id' => 1001,
            'work_origin_type' => CompletedWork::ORIGIN_JOURNAL,
            'quantity' => 10,
            'completed_quantity' => 8,
            'price' => 100,
            'total_amount' => 800,
            'completion_date' => '2026-04-10',
            'status' => 'confirmed',
        ]);

        $act = ContractPerformanceAct::create([
            'contract_id' => $contract->id,
            'project_id' => $project->id,
            'act_document_number' => '1',
            'act_date' => '2026-04-15',
            'amount' => 300,
            'is_approved' => true,
            'approval_date' => '2026-04-15',
        ]);

        PerformanceActLine::create([
            'performance_act_id' => $act->id,
            'completed_work_id' => $work->id,
            'line_type' => PerformanceActLine::TYPE_COMPLETED_WORK,
            'title' => 'Работа из журнала',
            'quantity' => 3,
            'unit_price' => 100,
            'amount' => 300,
        ]);

        $available = app(ActingAvailabilityService::class)->getAvailableWorks(
            $contract->id,
            '2026-04-01',
            '2026-04-30'
        );

        $this->assertCount(1, $available);
        $this->assertSame($work->id, $available[0]['id']);
        $this->assertSame(5.0, $available[0]['available_quantity']);
        $this->assertSame(500.0, $available[0]['available_amount']);
    }

    public function test_fully_acted_work_is_not_returned(): void
    {
        [$contract, $project, $organization] = $this->createContract();

        $work = CompletedWork::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contract_id' => $contract->id,
            'journal_entry_id' => 1002,
            'work_origin_type' => CompletedWork::ORIGIN_JOURNAL,
            'quantity' => 4,
            'completed_quantity' => null,
            'price' => 250,
            'total_amount' => 1000,
            'completion_date' => '2026-04-10',
            'status' => 'confirmed',
        ]);
        $act = ContractPerformanceAct::create([
            'contract_id' => $contract->id,
            'project_id' => $project->id,
            'act_document_number' => '2',
            'act_date' => '2026-04-15',
            'amount' => 1000,
            'is_approved' => true,
        ]);
        PerformanceActLine::create([
            'performance_act_id' => $act->id,
            'completed_work_id' => $work->id,
            'line_type' => PerformanceActLine::TYPE_COMPLETED_WORK,
            'title' => 'Работа из журнала',
            'quantity' => 4,
            'unit_price' => 250,
            'amount' => 1000,
        ]);

        $available = app(ActingAvailabilityService::class)->getAvailableWorks(
            $contract->id,
            '2026-04-01',
            '2026-04-30'
        );

        $this->assertSame([], $available);
    }

    public function test_available_works_include_only_journal_origin_facts(): void
    {
        [$contract, $project, $organization] = $this->createContract();

        CompletedWork::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contract_id' => $contract->id,
            'journal_entry_id' => 1003,
            'work_origin_type' => CompletedWork::ORIGIN_JOURNAL,
            'quantity' => 2,
            'completed_quantity' => null,
            'price' => 100,
            'total_amount' => 200,
            'completion_date' => '2026-04-10',
            'status' => 'confirmed',
        ]);
        CompletedWork::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contract_id' => $contract->id,
            'journal_entry_id' => null,
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
            'quantity' => 2,
            'completed_quantity' => null,
            'price' => 100,
            'total_amount' => 200,
            'completion_date' => '2026-04-10',
            'status' => 'confirmed',
        ]);

        $available = app(ActingAvailabilityService::class)->getAvailableWorks(
            $contract->id,
            '2026-04-01',
            '2026-04-30'
        );

        $this->assertCount(1, $available);
        $this->assertSame(CompletedWork::ORIGIN_JOURNAL, $available[0]['work_origin_type']);
        $this->assertSame(1003, $available[0]['journal_entry_id']);
    }

    public function test_available_work_contains_user_facing_names(): void
    {
        [$contract, $project, $organization] = $this->createContract();

        $estimate = Estimate::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'name' => 'Основная смета',
            'status' => 'approved',
            'total_amount' => 50000,
        ]);
        $estimateItem = EstimateItem::create([
            'estimate_id' => $estimate->id,
            'position_number' => '5',
            'item_type' => 'work',
            'name' => 'Бетонирование фундамента',
            'quantity' => 30,
            'unit_price' => 1200,
            'total_amount' => 36000,
        ]);
        $journal = ConstructionJournal::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contract_id' => $contract->id,
            'name' => 'Общий журнал работ',
            'journal_number' => '1',
        ]);
        $entry = ConstructionJournalEntry::create([
            'journal_id' => $journal->id,
            'estimate_id' => $estimate->id,
            'entry_date' => '2026-04-29',
            'entry_number' => 14,
            'work_description' => 'Бетонирование',
            'status' => 'approved',
        ]);
        $work = CompletedWork::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contract_id' => $contract->id,
            'estimate_item_id' => $estimateItem->id,
            'journal_entry_id' => $entry->id,
            'work_origin_type' => CompletedWork::ORIGIN_JOURNAL,
            'quantity' => 30,
            'completed_quantity' => 30,
            'price' => 1200,
            'total_amount' => 36000,
            'completion_date' => '2026-04-29',
            'status' => 'confirmed',
        ]);

        $available = app(ActingAvailabilityService::class)->getAvailableWorks(
            $contract->id,
            '2026-04-01',
            '2026-04-30'
        );

        $this->assertCount(1, $available);
        $this->assertSame($work->id, $available[0]['id']);
        $this->assertSame('Бетонирование фундамента', $available[0]['work_title']);
        $this->assertSame('Бетонирование фундамента', $available[0]['estimate_item_name']);
        $this->assertSame('5', $available[0]['estimate_item_position_number']);
        $this->assertSame(14, $available[0]['journal_entry_number']);
        $this->assertSame('1', $available[0]['journal_number']);
    }

    private function createContract(): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $contractor = Contractor::create([
            'organization_id' => $organization->id,
            'name' => 'Подрядчик',
        ]);
        $contract = Contract::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => 'AV-1',
            'date' => '2026-04-01',
            'subject' => 'Работы',
            'total_amount' => 100000,
            'status' => 'active',
        ]);

        return [$contract, $project, $organization];
    }
}
