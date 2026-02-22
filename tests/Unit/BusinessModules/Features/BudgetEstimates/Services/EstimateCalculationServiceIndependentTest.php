<?php

namespace Tests\Unit\BusinessModules\Features\BudgetEstimates\Services;

use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCacheService;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Repositories\EstimateItemRepository;
use App\Repositories\EstimateSectionRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EstimateCalculationServiceIndependentTest extends TestCase
{
    use RefreshDatabase;

    protected EstimateCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->service = new EstimateCalculationService(
            new EstimateSectionRepository(),
            new EstimateItemRepository(),
            app(EstimateCacheService::class)
        );
    }

    public function test_estimate_total_is_sum_of_items_total_amount()
    {
        // 1. Arrange
        $estimate = Estimate::factory()->create([
            'vat_rate' => 0,
            'overhead_rate' => 10,
            'profit_rate' => 5,
        ]);

        // Позиция 1: "Честная"
        EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'quantity' => 10,
            'unit_price' => 100,
            'total_amount' => 1000,
            'is_manual' => false,
        ]);

        // Позиция 2: С "кривым" Итого из Excel (например, копейка расхождения)
        // Мы ожидаем, что сервис исправит её на 20 * 50 = 1000
        EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'quantity' => 20,
            'unit_price' => 50,
            'current_total_amount' => 1000.05, // "Кривой" хардкод из Excel
            'total_amount' => 1000.05,
            'is_manual' => true,
        ]);

        // 2. Act
        $this->service->recalculateAll($estimate);
        $estimate->refresh();

        // 3. Assert
        
        // Проверяем, что вторая позиция стала 1000 ("честный" расчет Q*P)
        $item2 = EstimateItem::where('estimate_id', $estimate->id)->where('quantity', 20)->first();
        $this->assertEquals(1000.00, (float)$item2->total_amount, 'Позиция должна быть пересчитана честно по Q*P');

        // Итого сметы должно быть 1000 + 1000 = 2000
        $this->assertEquals(2000.00, (float)$estimate->total_amount, 'Итого сметы должно быть суммой Итого позиций');
        
        // Проверяем, что сумма компонентов тоже сходится (благодаря пересчету ПЗ внутри calculateItemTotal)
        $sumComponents = $estimate->total_direct_costs + $estimate->total_overhead_costs + $estimate->total_estimated_profit + $estimate->total_equipment_costs;
        $this->assertEquals(2000.00, (float)$sumComponents, 'Сумма компонентов должна совпадать с Итого');
    }

    public function test_estimate_total_with_equipment()
    {
        $estimate = Estimate::factory()->create(['vat_rate' => 0]);

        // Работа
        EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'quantity' => 1,
            'unit_price' => 1000,
            'item_type' => 'work',
        ]);

        // Оборудование
        EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'quantity' => 1,
            'unit_price' => 5000,
            'item_type' => \App\Enums\EstimatePositionItemType::EQUIPMENT->value,
        ]);

        $this->service->recalculateAll($estimate);
        $estimate->refresh();

        $this->assertEquals(6000, (float)$estimate->total_amount);
        $this->assertEquals(5000, (float)$estimate->total_equipment_costs);
    }
}
