<?php

namespace App\BusinessModules\Addons\AIEstimates\Services;

use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService;

class EstimateBuilderService
{
    public function __construct(
        protected ?EstimateCalculationService $calculationService = null
    ) {
        // Опциональная зависимость - если нет, создадим упрощенные расчеты
        if (!$this->calculationService) {
            $this->calculationService = app(EstimateCalculationService::class);
        }
    }

    public function buildDraft(array $aiResponse, array $matchedPositions, int $projectId): array
    {
        $sections = $this->buildSections($aiResponse['sections'] ?? [], $matchedPositions);
        $items = $this->buildItems($matchedPositions);
        $totalCost = $this->calculateTotalCost($items);
        $averageConfidence = $this->calculateAverageConfidence($matchedPositions);

        return [
            'estimate_data' => [
                'project_id' => $projectId,
                'name' => 'AI Сгенерированная смета',
                'status' => 'draft',
                'is_ai_generated' => true,
            ],
            'sections' => $sections,
            'items' => $items,
            'total_cost' => $totalCost,
            'average_confidence' => $averageConfidence,
        ];
    }

    protected function buildSections(array $aiSections, array $matchedPositions): array
    {
        $sections = [];

        foreach ($aiSections as $index => $aiSection) {
            $sections[] = [
                'name' => $aiSection['name'] ?? "Раздел " . ($index + 1),
                'order' => $aiSection['order'] ?? ($index + 1),
                'number' => ($index + 1) . '.',
                'description' => null,
            ];
        }

        return $sections;
    }

    protected function buildItems(array $matchedPositions): array
    {
        $items = [];

        foreach ($matchedPositions as $position) {
            $matched = $position['matched_catalog'] ?? null;
            
            if (!$matched) {
                // Позиция не найдена в каталоге, создаем как есть
                $items[] = [
                    'name' => $position['description'] ?? '',
                    'unit' => $position['unit'] ?? 'шт',
                    'quantity' => $position['quantity'] ?? 1,
                    'price' => 0,
                    'total' => 0,
                    'is_matched' => false,
                    'confidence' => $position['confidence'] ?? 0.5,
                ];
            } else {
                // Позиция найдена в каталоге
                $quantity = $position['quantity'] ?? 1;
                $price = $matched['price'] ?? 0;
                
                $items[] = [
                    'catalog_id' => $matched['catalog_id'] ?? null,
                    'code' => $matched['code'] ?? '',
                    'name' => $matched['name'] ?? $position['description'],
                    'unit' => $matched['unit'] ?? $position['unit'],
                    'quantity' => $quantity,
                    'price' => $price,
                    'total' => $quantity * $price,
                    'is_matched' => true,
                    'confidence' => $matched['confidence'] ?? 0.5,
                    'category' => $matched['category'] ?? null,
                ];
            }
        }

        return $items;
    }

    protected function calculateTotalCost(array $items): float
    {
        return array_reduce($items, function ($total, $item) {
            return $total + ($item['total'] ?? 0);
        }, 0.0);
    }

    protected function calculateAverageConfidence(array $matchedPositions): float
    {
        if (empty($matchedPositions)) {
            return 0.0;
        }

        $totalConfidence = 0.0;
        $count = 0;

        foreach ($matchedPositions as $position) {
            if (isset($position['matched_catalog']['confidence'])) {
                $totalConfidence += $position['matched_catalog']['confidence'];
                $count++;
            } elseif (isset($position['confidence'])) {
                $totalConfidence += $position['confidence'];
                $count++;
            }
        }

        if ($count === 0) {
            return 0.0;
        }

        return round($totalConfidence / $count, 4);
    }
}
