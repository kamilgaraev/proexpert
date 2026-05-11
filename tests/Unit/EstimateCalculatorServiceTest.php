<?php

namespace Tests\Unit\BusinessModules\Features\BudgetEstimates\Services\Calculation;

use App\BusinessModules\Features\BudgetEstimates\Services\Calculation\EstimateCalculatorService;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EstimateCalculatorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_recalculate_updates_totals_correctly()
    {
        // 1. Arrange
        $organization = Organization::factory()->create();
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
        ]);

        /** @var Estimate $estimate */
        $estimate = Estimate::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'EST-TEST-001',
            'name' => 'Test estimate',
            'type' => 'local',
            'status' => 'draft',
            'estimate_date' => now()->toDateString(),
            'vat_rate' => 0,
            'overhead_rate' => 0,
            'profit_rate' => 0,
        ]);

        // Item 1: Base Price with Index
        // Base: 100, Qty: 10, Index: 5 -> Current: 5000
        EstimateItem::create([
            'estimate_id' => $estimate->id,
            'position_number' => '1',
            'name' => 'Indexed work',
            'quantity' => 10,
            'base_unit_price' => 100,
            'price_index' => 5,
            'unit_price' => 500, // 100 * 5
            'current_total_amount' => 5000,
            
            // Components breakdown (Base)
            'base_materials_cost' => 60,
            'base_machinery_cost' => 30,
            'base_labor_cost' => 10,
        ]);

        // Item 2: Pure Current Price (No Base)
        // Price: 200, Qty: 5 -> Current: 1000
        EstimateItem::create([
            'estimate_id' => $estimate->id,
            'position_number' => '2',
            'name' => 'Current work',
            'quantity' => 5,
            'base_unit_price' => 0,
            'price_index' => 0,
            'unit_price' => 200,
            'current_total_amount' => 1000,
        ]);

        $service = new EstimateCalculatorService();

        // 2. Act
        $service->recalculate($estimate);
        $estimate->refresh();

        // 3. Assert
        
        // Base Totals (Only Item 1 contributes)
        // Direct Base = 100 * 10 = 1000
        $this->assertEquals(1000, $estimate->total_base_direct_costs);
        
        // Base Components
        $this->assertEquals(600, $estimate->total_base_materials_cost); // 60 * 10
        $this->assertEquals(300, $estimate->total_base_machinery_cost); // 30 * 10
        $this->assertEquals(100, $estimate->total_base_labor_cost);     // 10 * 10

        // Current Totals (Item 1 + Item 2)
        // Item 1: 5000
        // Item 2: 1000
        // Total Direct = 6000
        $this->assertEquals(6000, $estimate->total_direct_costs);
        
        // Total Amount (since overhead/profit/vat are 0)
        $this->assertEquals(6000, $estimate->total_amount);
    }
}
