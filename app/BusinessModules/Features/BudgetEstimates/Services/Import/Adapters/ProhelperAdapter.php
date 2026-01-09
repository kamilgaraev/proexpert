<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Adapters;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\ProhelperEstimateParser;
use Illuminate\Support\Facades\Log;

/**
 * Адаптер для импорта смет в формате Prohelper
 * 
 * Восстанавливает структуру, связи и метаданные из экспортированного файла
 */
class ProhelperAdapter implements EstimateAdapterInterface
{
    public function supports(string $estimateType): bool
    {
        return $estimateType === 'prohelper';
    }

    public function transform(EstimateImportRowDTO $row): EstimateImportRowDTO
    {
        // Prohelper format doesn't need transformation - data is already in correct format
        // Just validate and return as is
        
        Log::debug('prohelper_adapter.transform', [
            'position_number' => $row->positionNumber,
            'name' => $row->name,
            'item_type' => $row->itemType,
            'has_metadata' => isset($row->rawData['prohelper_metadata']),
        ]);

        return $row;
    }

    public function getDescription(): string
    {
        return 'Адаптер для импорта смет Prohelper с полным восстановлением метаданных';
    }

    /**
     * Специальный метод для восстановления полной структуры из метаданных
     * 
     * @param ProhelperEstimateParser $parser
     * @return array Структура для создания сметы
     */
    public function restoreStructure(ProhelperEstimateParser $parser): array
    {
        $metadata = $parser->getMetadata();
        $sections = $parser->getSections();
        $items = $parser->getItems();

        Log::info('prohelper_adapter.restoring_structure', [
            'sections_count' => count($sections),
            'items_count' => count($items),
            'estimate_id' => $metadata['estimate_id'] ?? null,
        ]);

        return [
            'metadata' => $metadata,
            'sections' => $this->prepareSections($sections),
            'items' => $this->prepareItems($items),
            'calculation_settings' => $parser->getCalculationSettings(),
        ];
    }

    /**
     * Подготовить разделы для создания
     */
    protected function prepareSections(array $sections): array
    {
        $prepared = [];

        foreach ($sections as $section) {
            $prepared[] = [
                'original_id' => $section['id'],
                'parent_section_id' => $section['parent_section_id'],
                'section_number' => $section['section_number'],
                'name' => $section['name'],
                'description' => $section['description'] ?? null,
                'is_summary' => $section['is_summary'] ?? false,
                'sort_order' => $prepared ? count($prepared) : 0,
            ];
        }

        return $prepared;
    }

    /**
     * Подготовить позиции для создания
     */
    protected function prepareItems(array $items): array
    {
        $prepared = [];

        foreach ($items as $item) {
            $prepared[] = [
                'original_id' => $item['id'],
                'section_id' => $item['section_id'],
                'parent_work_id' => $item['parent_work_id'],
                'catalog_item_id' => $item['catalog_item_id'],
                'item_type' => $item['item_type'],
                'position_number' => $item['position_number'],
                'name' => $item['name'],
                'description' => $item['description'] ?? null,
                'normative_rate_code' => $item['normative_rate_code'] ?? null,
                'measurement_unit' => $item['measurement_unit'],
                'quantity' => $item['quantity'],
                'quantity_total' => $item['quantity_total'],
                'unit_price' => $item['unit_price'] ?? 0,
                'base_unit_price' => $item['base_unit_price'] ?? 0,
                'current_unit_price' => $item['current_unit_price'] ?? 0,
                'direct_costs' => $item['direct_costs'] ?? 0,
                'materials_cost' => $item['materials_cost'] ?? 0,
                'machinery_cost' => $item['machinery_cost'] ?? 0,
                'labor_cost' => $item['labor_cost'] ?? 0,
                'overhead_amount' => $item['overhead_amount'] ?? 0,
                'profit_amount' => $item['profit_amount'] ?? 0,
                'total_amount' => $item['total_amount'] ?? 0,
                'applied_coefficients' => $item['applied_coefficients'] ?? null,
                'coefficient_total' => $item['coefficient_total'] ?? null,
                'custom_resources' => $item['custom_resources'] ?? null,
                'metadata' => $item['metadata'] ?? null,
                'is_manual' => $item['is_manual'] ?? false,
                'is_not_accounted' => $item['is_not_accounted'] ?? false,
            ];
        }

        return $prepared;
    }
}
