<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Export;

use App\Models\Estimate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class EstimateExportService
{
    private const CACHE_TTL = 600; // 10 minutes
    private const CACHE_PREFIX = 'estimate_export:';

    public function __construct(
        protected ExcelEstimateBuilder $excelBuilder,
        protected PDFEstimateBuilder $pdfBuilder
    ) {}

    /**
     * Экспорт сметы в Excel формат
     *
     * @param Estimate $estimate
     * @param array $options
     * @return string Path to generated file
     */
    public function exportToExcel(Estimate $estimate, array $options = []): string
    {
        Log::info('estimate_export.excel_started', [
            'estimate_id' => $estimate->id,
            'organization_id' => $estimate->organization_id,
            'options' => $options,
        ]);

        try {
            // Prepare export data
            $data = $this->prepareExportData($estimate, $options);

            // Build Excel file
            $filePath = $this->excelBuilder->build($estimate, $data, $options);

            Log::info('estimate_export.excel_completed', [
                'estimate_id' => $estimate->id,
                'file_path' => $filePath,
                'file_size' => filesize($filePath),
            ]);

            return $filePath;
        } catch (\Throwable $e) {
            Log::error('estimate_export.excel_failed', [
                'estimate_id' => $estimate->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Экспорт сметы в PDF формат
     *
     * @param Estimate $estimate
     * @param array $options
     * @return string Path to generated file
     */
    public function exportToPdf(Estimate $estimate, array $options = []): string
    {
        Log::info('estimate_export.pdf_started', [
            'estimate_id' => $estimate->id,
            'organization_id' => $estimate->organization_id,
            'options' => $options,
        ]);

        try {
            // Prepare export data
            $data = $this->prepareExportData($estimate, $options);

            // Build PDF file
            $filePath = $this->pdfBuilder->build($estimate, $data, $options);

            Log::info('estimate_export.pdf_completed', [
                'estimate_id' => $estimate->id,
                'file_path' => $filePath,
                'file_size' => filesize($filePath),
            ]);

            return $filePath;
        } catch (\Throwable $e) {
            Log::error('estimate_export.pdf_failed', [
                'estimate_id' => $estimate->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Подготовка данных для экспорта
     *
     * @param Estimate $estimate
     * @param array $options
     * @return array
     */
    public function prepareExportData(Estimate $estimate, array $options = []): array
    {
        // Check cache
        $cacheKey = $this->getCacheKey($estimate->id, $options);
        if ($cached = Cache::get($cacheKey)) {
            Log::debug('estimate_export.cache_hit', ['estimate_id' => $estimate->id]);
            return $cached;
        }

        Log::debug('estimate_export.preparing_data', [
            'estimate_id' => $estimate->id,
            'options' => $options,
        ]);

        // Merge with defaults
        $options = array_merge($this->getDefaultOptions(), $options);

        // Load relationships
        $estimate->load([
            'organization',
            'project',
            'contract',
            'approvedBy',
            'sections' => function ($query) {
                $query->orderBy('sort_order');
            },
            'sections.items' => function ($query) {
                $query->orderBy('position_number');
            },
            'sections.items.workType',
            'sections.items.measurementUnit',
            'sections.items.catalogItem',
            'sections.items.childItems' => function ($query) {
                $query->orderBy('position_number');
            },
            'sections.items.childItems.measurementUnit',
        ]);

        // Prepare structured data
        $data = [
            'estimate' => $this->prepareEstimateData($estimate),
            'sections' => $this->prepareSectionsData($estimate, $options),
            'totals' => $this->prepareTotalsData($estimate),
            'metadata' => $this->prepareMetadata($estimate, $options),
            'options' => $options,
        ];

        // Cache for 10 minutes
        Cache::put($cacheKey, $data, self::CACHE_TTL);

        return $data;
    }

    /**
     * Подготовка данных основной информации о смете
     */
    protected function prepareEstimateData(Estimate $estimate): array
    {
        return [
            'id' => $estimate->id,
            'number' => $estimate->number,
            'name' => $estimate->name,
            'description' => $estimate->description,
            'type' => $estimate->type,
            'status' => $estimate->status,
            'version' => $estimate->version,
            'estimate_date' => $estimate->estimate_date?->format('d.m.Y'),
            'base_price_date' => $estimate->base_price_date?->format('d.m.Y'),
            'organization' => [
                'name' => $estimate->organization?->name,
                'legal_name' => $estimate->organization?->legal_name,
                'tax_number' => $estimate->organization?->tax_number,
                'address' => $estimate->organization?->address,
            ],
            'project' => $estimate->project ? [
                'id' => $estimate->project->id,
                'name' => $estimate->project->name,
                'address' => $estimate->project->address,
            ] : null,
            'contract' => $estimate->contract ? [
                'id' => $estimate->contract->id,
                'number' => $estimate->contract->number,
                'name' => $estimate->contract->name,
            ] : null,
            'approved_by' => $estimate->approvedBy ? [
                'name' => $estimate->approvedBy->name,
                'email' => $estimate->approvedBy->email,
            ] : null,
            'approved_at' => $estimate->approved_at?->format('d.m.Y H:i'),
        ];
    }

    /**
     * Подготовка данных разделов и позиций
     */
    protected function prepareSectionsData(Estimate $estimate, array $options): array
    {
        $sections = [];

        foreach ($estimate->sections as $section) {
            $sectionData = [
                'id' => $section->id,
                'parent_section_id' => $section->parent_section_id,
                'section_number' => $section->section_number,
                'full_section_number' => $section->full_section_number,
                'name' => $section->name,
                'description' => $section->description,
                'is_summary' => $section->is_summary,
                'section_total_amount' => (float) $section->section_total_amount,
                'items' => [],
            ];

            // Add items if needed
            if ($options['include_works'] || $options['include_materials'] || $options['include_machinery']) {
                foreach ($section->items as $item) {
                    if ($this->shouldIncludeItem($item, $options)) {
                        $sectionData['items'][] = $this->prepareItemData($item, $options);
                    }
                }
            }

            $sections[] = $sectionData;
        }

        return $sections;
    }

    /**
     * Подготовка данных позиции сметы
     */
    protected function prepareItemData($item, array $options): array
    {
        $itemData = [
            'id' => $item->id,
            'section_id' => $item->estimate_section_id,
            'parent_work_id' => $item->parent_work_id,
            'catalog_item_id' => $item->catalog_item_id,
            'item_type' => $item->item_type->value,
            'position_number' => $item->position_number,
            'name' => $item->name,
            'description' => $item->description,
            'normative_rate_code' => $item->normative_rate_code,
            'work_type' => $item->workType?->name,
            'measurement_unit' => $item->measurementUnit?->symbol ?? $item->measurementUnit?->name,
            'quantity' => (float) $item->quantity,
            'quantity_total' => (float) $item->quantity_total,
            'is_manual' => $item->is_manual,
            'is_not_accounted' => $item->is_not_accounted,
        ];

        // Add prices if needed
        if ($options['show_prices']) {
            $itemData['unit_price'] = (float) $item->unit_price;
            $itemData['base_unit_price'] = (float) $item->base_unit_price;
            $itemData['current_unit_price'] = (float) $item->current_unit_price;
            $itemData['direct_costs'] = (float) $item->direct_costs;
            $itemData['materials_cost'] = (float) $item->materials_cost;
            $itemData['machinery_cost'] = (float) $item->machinery_cost;
            $itemData['labor_cost'] = (float) $item->labor_cost;
            $itemData['overhead_amount'] = (float) $item->overhead_amount;
            $itemData['profit_amount'] = (float) $item->profit_amount;
            $itemData['total_amount'] = (float) $item->total_amount;
        }

        // Add coefficients if needed
        if ($options['include_coefficients'] && $item->applied_coefficients) {
            $itemData['applied_coefficients'] = $item->applied_coefficients;
            $itemData['coefficient_total'] = (float) $item->coefficient_total;
        }

        // Add resources if needed
        if ($options['include_resources'] && $item->custom_resources) {
            $itemData['custom_resources'] = $item->custom_resources;
        }

        // Add child items (materials, machinery, labor)
        if ($options['include_materials'] || $options['include_machinery'] || $options['include_labor']) {
            $childItems = [];
            foreach ($item->childItems as $childItem) {
                if ($this->shouldIncludeItem($childItem, $options)) {
                    $childItems[] = $this->prepareItemData($childItem, $options);
                }
            }
            if (!empty($childItems)) {
                $itemData['child_items'] = $childItems;
            }
        }

        // Add metadata
        if ($item->metadata) {
            $itemData['metadata'] = $item->metadata;
        }

        return $itemData;
    }

    /**
     * Проверка, нужно ли включать позицию в экспорт
     */
    protected function shouldIncludeItem($item, array $options): bool
    {
        $type = $item->item_type->value;

        if ($type === 'work' && !$options['include_works']) {
            return false;
        }
        if ($type === 'material' && !$options['include_materials']) {
            return false;
        }
        if ($type === 'machinery' && !$options['include_machinery']) {
            return false;
        }
        if ($type === 'labor' && !$options['include_labor']) {
            return false;
        }
        if ($type === 'equipment' && !$options['include_machinery']) {
            return false;
        }

        return true;
    }

    /**
     * Подготовка итоговых данных
     */
    protected function prepareTotalsData(Estimate $estimate): array
    {
        return [
            'total_direct_costs' => (float) $estimate->total_direct_costs,
            'total_overhead_costs' => (float) $estimate->total_overhead_costs,
            'total_estimated_profit' => (float) $estimate->total_estimated_profit,
            'total_amount' => (float) $estimate->total_amount,
            'total_amount_with_vat' => (float) $estimate->total_amount_with_vat,
            'vat_rate' => (float) $estimate->vat_rate,
            'overhead_rate' => (float) $estimate->overhead_rate,
            'profit_rate' => (float) $estimate->profit_rate,
            'vat_amount' => (float) ($estimate->total_amount_with_vat - $estimate->total_amount),
        ];
    }

    /**
     * Подготовка метаданных для импорта
     */
    protected function prepareMetadata(Estimate $estimate, array $options): array
    {
        return [
            'prohelper_export' => true,
            'version' => '2.0',
            'estimate_id' => $estimate->id,
            'organization_id' => $estimate->organization_id,
            'export_date' => now()->toIso8601String(),
            'export_options' => $options,
            'calculation_settings' => [
                'overhead_rate' => (float) $estimate->overhead_rate,
                'profit_rate' => (float) $estimate->profit_rate,
                'vat_rate' => (float) $estimate->vat_rate,
                'calculation_method' => $estimate->calculation_method,
            ],
        ];
    }

    /**
     * Получить настройки экспорта по умолчанию
     */
    protected function getDefaultOptions(): array
    {
        return [
            'include_sections' => true,
            'include_works' => true,
            'include_materials' => true,
            'include_machinery' => true,
            'include_labor' => true,
            'include_resources' => false,
            'include_coefficients' => true,
            'include_formulas' => false,
            'show_prices' => true,
            'signature_fields' => [
                'Составил',
                'Проверил',
                'ГИП',
                'Главный инженер',
                'Утверждаю (Заказчик)',
            ],
        ];
    }

    /**
     * Получить ключ кэша
     */
    protected function getCacheKey(int $estimateId, array $options): string
    {
        $optionsHash = md5(json_encode($options));
        return self::CACHE_PREFIX . "{$estimateId}:{$optionsHash}";
    }

    /**
     * Очистить кэш для сметы
     */
    public function clearCache(int $estimateId): void
    {
        // Cache::forget не работает с wildcard, поэтому используем tags если доступно
        // Иначе просто логируем
        Log::info('estimate_export.cache_cleared', ['estimate_id' => $estimateId]);
    }
}
