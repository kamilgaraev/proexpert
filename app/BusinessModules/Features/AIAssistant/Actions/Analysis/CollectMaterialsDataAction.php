<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Analysis;

use App\Models\Project;
use App\BusinessModules\Features\BasicWarehouse\Models\Material;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseStock;
use App\Models\EstimateItem;
use Illuminate\Support\Facades\DB;

class CollectMaterialsDataAction
{
    /**
     * Собрать данные по материалам проекта
     *
     * @param int $projectId
     * @param int $organizationId
     * @return array
     */
    public function execute(int $projectId, int $organizationId): array
    {
        $project = Project::where('id', $projectId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        // Остатки на складах
        $stockData = $this->collectStockData($organizationId);
        
        // Материалы в сметах проекта
        $requiredMaterials = $this->collectRequiredMaterials($project);
        
        // Анализ дефицита
        $deficitAnalysis = $this->analyzeDeficit($stockData, $requiredMaterials);
        
        // Прогноз обеспеченности
        $daysOfSupply = $this->calculateDaysOfSupply($stockData, $requiredMaterials, $project);

        return [
            'project_name' => $project->name,
            'warehouse_stock' => $stockData,
            'required_materials' => $requiredMaterials,
            'deficit_analysis' => $deficitAnalysis,
            'days_of_supply' => $daysOfSupply,
            'total_purchase_cost' => $deficitAnalysis['total_cost'],
            'materials_health' => $this->assessMaterialsHealth($deficitAnalysis, $daysOfSupply),
        ];
    }

    /**
     * Собрать данные остатков на складе
     */
    private function collectStockData(int $organizationId): array
    {
        $stocks = WarehouseStock::with(['material.measurementUnit'])
            ->whereHas('warehouse', function ($query) use ($organizationId) {
                $query->where('organization_id', $organizationId);
            })
            ->get();

        $stockArray = [];
        $totalValue = 0;

        foreach ($stocks as $stock) {
            $available = $stock->quantity - $stock->reserved_quantity;
            $value = $available * ($stock->material->price ?? 0);
            
            $stockArray[] = [
                'material_id' => $stock->material_id,
                'name' => $stock->material->name,
                'quantity' => (float) $stock->quantity,
                'reserved' => (float) $stock->reserved_quantity,
                'available' => (float) $available,
                'unit' => $stock->material->measurementUnit->short_name ?? 'шт',
                'price' => (float) ($stock->material->price ?? 0),
                'value' => $value,
            ];
            
            $totalValue += $value;
        }

        return [
            'materials' => $stockArray,
            'total_materials_count' => count($stockArray),
            'total_inventory_value' => $totalValue,
        ];
    }

    /**
     * Собрать требуемые материалы из смет
     */
    private function collectRequiredMaterials(Project $project): array
    {
        // Получаем сметы проекта
        $estimates = $project->estimates;
        
        $requiredMaterials = [];

        foreach ($estimates as $estimate) {
            $items = $estimate->items()
                ->with(['resources.material.measurementUnit'])
                ->get();

            foreach ($items as $item) {
                foreach ($item->resources as $resource) {
                    if ($resource->resource_type === 'material' && $resource->material) {
                        $materialId = $resource->material_id;
                        
                        if (!isset($requiredMaterials[$materialId])) {
                            $requiredMaterials[$materialId] = [
                                'material_id' => $materialId,
                                'name' => $resource->material->name,
                                'required_quantity' => 0,
                                'unit' => $resource->material->measurementUnit->short_name ?? 'шт',
                                'estimated_cost' => (float) ($resource->material->price ?? 0),
                            ];
                        }
                        
                        $requiredMaterials[$materialId]['required_quantity'] += (float) $resource->quantity;
                    }
                }
            }
        }

        return array_values($requiredMaterials);
    }

    /**
     * Анализ дефицита материалов
     */
    private function analyzeDeficit(array $stockData, array $requiredMaterials): array
    {
        $deficitItems = [];
        $totalCost = 0;

        // Создаем индекс остатков по material_id
        $stockIndex = collect($stockData['materials'])->keyBy('material_id');

        foreach ($requiredMaterials as $required) {
            $materialId = $required['material_id'];
            $stock = $stockIndex->get($materialId);
            
            $available = $stock ? $stock['available'] : 0;
            $deficit = max(0, $required['required_quantity'] - $available);

            if ($deficit > 0) {
                $cost = $deficit * $required['estimated_cost'];
                
                $deficitItems[] = [
                    'material' => $required['name'],
                    'required' => $required['required_quantity'],
                    'available' => $available,
                    'deficit' => $deficit,
                    'unit' => $required['unit'],
                    'estimated_cost' => $cost,
                ];
                
                $totalCost += $cost;
            }
        }

        return [
            'deficit_items' => $deficitItems,
            'deficit_count' => count($deficitItems),
            'total_cost' => $totalCost,
        ];
    }

    /**
     * Рассчитать на сколько дней хватит материалов
     */
    private function calculateDaysOfSupply(array $stockData, array $requiredMaterials, Project $project): int
    {
        if (empty($requiredMaterials) || !$project->end_date || !$project->start_date) {
            return 0;
        }

        $totalDays = $project->start_date->diffInDays($project->end_date);
        if ($totalDays <= 0) {
            return 0;
        }

        // Создаем индекс остатков по material_id
        $stockIndex = collect($stockData['materials'])->keyBy('material_id');

        $supplyRatios = [];

        foreach ($requiredMaterials as $required) {
            if ($required['required_quantity'] <= 0) {
                continue;
            }
            
            $stock = $stockIndex->get($required['material_id']);
            $available = $stock ? $stock['available'] : 0;
            
            $ratio = $available / $required['required_quantity'];
            $supplyRatios[] = $ratio;
        }

        if (empty($supplyRatios)) {
            return 0;
        }

        // Минимальный коэффициент обеспеченности
        $minRatio = min($supplyRatios);
        
        return (int) floor($minRatio * $totalDays);
    }

    /**
     * Оценить здоровье материальной базы
     */
    private function assessMaterialsHealth(array $deficitAnalysis, int $daysOfSupply): string
    {
        $deficitCount = $deficitAnalysis['deficit_count'];
        
        if ($deficitCount > 10 || $daysOfSupply < 14) {
            return 'critical';
        }
        
        if ($deficitCount > 5 || $daysOfSupply < 30) {
            return 'warning';
        }
        
        return 'good';
    }
}

