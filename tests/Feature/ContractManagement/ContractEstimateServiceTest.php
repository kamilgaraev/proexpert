<?php

declare(strict_types=1);

namespace Tests\Feature\ContractManagement;

use App\BusinessModules\Features\ContractManagement\Services\ContractEstimateService;
use App\BusinessModules\Features\BudgetEstimates\Services\Integration\EstimateCoverageService;
use App\Models\Contract;
use App\Models\ContractEstimateItem;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContractEstimateServiceTest extends TestCase
{
    use RefreshDatabase;

    private ContractEstimateService $service;
    private EstimateCoverageService $coverageService;
    private Contract $contract;
    private Estimate $estimate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ContractEstimateService::class);
        $this->coverageService = app(EstimateCoverageService::class);

        $org = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $org->id]);
        
        $user = User::factory()->create();
        $user->organizations()->attach($org->id, ['role' => 'owner']);
        // Имитируем активную сессию
        $user->current_organization_id = $org->id;
        $this->actingAs($user);

        $this->contract = Contract::factory()->create([
            'organization_id' => $org->id,
            'project_id'      => $project->id,
            'total_amount'    => 1000000,
        ]);

        $this->estimate = Estimate::factory()->create([
            'organization_id' => $org->id,
            'project_id'      => $project->id,
        ]);
    }

    public function test_attach_parent_item_also_attaches_children(): void
    {
        $parent = EstimateItem::factory()->create([
            'estimate_id'    => $this->estimate->id,
            'quantity_total' => 10,
            'unit_price'     => 1000, // amount = 10000
        ]);

        $child1 = EstimateItem::factory()->create([
            'estimate_id'    => $this->estimate->id,
            'parent_work_id' => $parent->id,
            'quantity_total' => 5,
            'unit_price'     => 500, // amount = 2500
        ]);

        $this->service->attachItems($this->contract, $this->estimate, [$parent->id]);

        $this->assertDatabaseHas('contract_estimate_items', [
            'contract_id'      => $this->contract->id,
            'estimate_item_id' => $parent->id,
            'amount'           => 10000,
        ]);

        $this->assertDatabaseHas('contract_estimate_items', [
            'contract_id'      => $this->contract->id,
            'estimate_item_id' => $child1->id,
            'amount'           => 2500,
        ]);
    }

    public function test_same_item_can_belong_to_multiple_contracts(): void
    {
        $item = EstimateItem::factory()->create([
            'estimate_id'    => $this->estimate->id,
            'quantity_total' => 10,
            'unit_price'     => 100,
        ]);

        $contract2 = Contract::factory()->create([
            'organization_id' => $this->contract->organization_id,
            'project_id'      => $this->contract->project_id,
        ]);

        $this->service->attachItems($this->contract, $this->estimate, [$item->id]);
        $this->service->attachItems($contract2, $this->estimate, [$item->id]);

        $this->assertDatabaseCount('contract_estimate_items', 2);
        $this->assertEquals(2, $item->contractLinks()->count());
    }

    public function test_detach_parent_removes_children(): void
    {
        $parent = EstimateItem::factory()->create(['estimate_id' => $this->estimate->id]);
        $child = EstimateItem::factory()->create([
            'estimate_id'    => $this->estimate->id,
            'parent_work_id' => $parent->id,
        ]);

        $this->service->attachItems($this->contract, $this->estimate, [$parent->id]);
        $this->assertDatabaseCount('contract_estimate_items', 2);

        $this->service->detachItems($this->contract, [$parent->id]);
        $this->assertDatabaseCount('contract_estimate_items', 0);
    }

    public function test_calculate_contract_total_only_counts_linked_items(): void
    {
        $item1 = EstimateItem::factory()->create([
            'estimate_id' => $this->estimate->id,
            'quantity_total' => 1,
            'unit_price' => 500
        ]);

        $item2 = EstimateItem::factory()->create([
            'estimate_id' => $this->estimate->id,
            'quantity_total' => 1,
            'unit_price' => 700
        ]);

        // Привязываем только item1
        $this->service->attachItems($this->contract, $this->estimate, [$item1->id]);

        $total = $this->service->calculateContractEstimateTotal($this->contract);
        $this->assertEquals(500, $total);
    }

    public function test_full_coverage_attaches_all_root_billable_items(): void
    {
        EstimateItem::factory()->create([
            'estimate_id' => $this->estimate->id,
            'item_type' => 'work',
            'quantity' => 3,
            'quantity_total' => 3,
            'unit_price' => 100,
            'total_amount' => 300,
        ]);

        EstimateItem::factory()->create([
            'estimate_id' => $this->estimate->id,
            'item_type' => 'material',
            'quantity' => 100,
            'quantity_total' => 100,
            'unit_price' => 70,
            'total_amount' => 7000,
        ]);

        EstimateItem::factory()->create([
            'estimate_id' => $this->estimate->id,
            'item_type' => 'material',
            'quantity' => 7,
            'quantity_total' => 7,
            'unit_price' => 6000,
            'total_amount' => 42000,
        ]);

        EstimateItem::factory()->create([
            'estimate_id' => $this->estimate->id,
            'item_type' => 'machinery',
            'quantity' => 5,
            'quantity_total' => 5,
            'unit_price' => 10000,
            'total_amount' => 50000,
        ]);

        $this->coverageService->attachFullCoverage($this->contract, $this->estimate);

        $coverage = $this->coverageService->getCoverageForEstimate($this->estimate);
        $summary = $this->coverageService->getContractCoverageSummary($this->contract);

        $this->assertDatabaseCount('contract_estimate_items', 4);
        $this->assertEquals(4, $coverage['total_items']);
        $this->assertEquals('full_link', $coverage['coverage_status']);
        $this->assertEquals(4, $coverage['primary_contract']['linked_items_count']);
        $this->assertEquals(99300.0, $coverage['primary_contract']['linked_amount']);
        $this->assertEquals(4, $summary['summary']['linked_items_count']);
        $this->assertEquals(99300.0, $summary['summary']['linked_amount']);
    }

    public function test_partial_coverage_keeps_explicit_item_selection(): void
    {
        $item1 = EstimateItem::factory()->create([
            'estimate_id' => $this->estimate->id,
            'item_type' => 'work',
            'quantity_total' => 1,
            'unit_price' => 500,
            'total_amount' => 500,
        ]);

        EstimateItem::factory()->create([
            'estimate_id' => $this->estimate->id,
            'item_type' => 'material',
            'quantity_total' => 1,
            'unit_price' => 700,
            'total_amount' => 700,
        ]);

        $this->service->attachItems($this->contract, $this->estimate, [$item1->id]);

        $coverage = $this->coverageService->getCoverageForEstimate($this->estimate);

        $this->assertEquals(2, $coverage['total_items']);
        $this->assertEquals('partial_link', $coverage['coverage_status']);
        $this->assertEquals(1, $coverage['primary_contract']['linked_items_count']);
        $this->assertEquals(500.0, $coverage['primary_contract']['linked_amount']);
    }
}
