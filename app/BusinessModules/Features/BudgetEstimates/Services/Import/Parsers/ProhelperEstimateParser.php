<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers;

use App\BusinessModules\Features\BudgetEstimates\Contracts\EstimateImportParserInterface;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\Log;

class ProhelperEstimateParser implements EstimateImportParserInterface
{
    protected array $metadata = [];
    protected bool $isProhelperFormat = false;

    /**
     * Проверить, является ли файл форматом Prohelper
     */
    public function canParse(string $filePath): bool
    {
        try {
            $spreadsheet = IOFactory::load($filePath);
            
            // Check for _METADATA_ sheet
            $metadataSheet = null;
            foreach ($spreadsheet->getSheetNames() as $sheetName) {
                if ($sheetName === '_METADATA_') {
                    $metadataSheet = $spreadsheet->getSheetByName($sheetName);
                    break;
                }
            }

            if (!$metadataSheet) {
                return false;
            }

            // Try to parse metadata
            $metadataJson = $metadataSheet->getCell('A1')->getValue();
            $metadata = json_decode($metadataJson, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return false;
            }

            // Check for prohelper_export flag
            if (!isset($metadata['prohelper_export']) || !$metadata['prohelper_export']) {
                return false;
            }

            // Store metadata for later use
            $this->metadata = $metadata;
            $this->isProhelperFormat = true;

            Log::info('prohelper_parser.detected', [
                'estimate_id' => $metadata['estimate_id'] ?? null,
                'version' => $metadata['version'] ?? null,
                'export_date' => $metadata['export_date'] ?? null,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::debug('prohelper_parser.detection_failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Парсить файл Prohelper формата
     */
    public function parse(string $filePath): \Generator
    {
        if (!$this->isProhelperFormat) {
            // Try to detect again
            if (!$this->canParse($filePath)) {
                throw new \RuntimeException('Not a Prohelper format file');
            }
        }

        Log::info('prohelper_parser.parsing_started', [
            'file' => $filePath,
            'items_count' => count($this->metadata['items'] ?? []),
            'sections_count' => count($this->metadata['sections'] ?? []),
        ]);

        // Parse items from metadata
        $items = $this->metadata['items'] ?? [];
        
        foreach ($items as $index => $item) {
            yield $this->convertToImportRowDTO($item, $index + 1);
        }

        Log::info('prohelper_parser.parsing_completed', [
            'total_items' => count($items),
        ]);
    }

    /**
     * Конвертировать данные Prohelper в EstimateImportRowDTO
     */
    protected function convertToImportRowDTO(array $item, int $rowNumber): EstimateImportRowDTO
    {
        // Determine parent section from metadata
        $sectionName = $this->getSectionName($item['section_id']);

        return new EstimateImportRowDTO(
            rowNumber: $rowNumber,
            positionNumber: $item['position_number'],
            code: $item['normative_rate_code'] ?? '',
            name: $item['name'],
            unit: $item['measurement_unit'] ?? '',
            quantity: (float) $item['quantity_total'],
            unitPrice: (float) ($item['unit_price'] ?? 0),
            totalPrice: (float) ($item['total_amount'] ?? 0),
            workType: $item['work_type'] ?? null,
            section: $sectionName,
            parentWork: $item['parent_work_id'] ? $this->getParentWorkName($item['parent_work_id']) : null,
            itemType: $item['item_type'],
            rawData: [
                'prohelper_metadata' => [
                    'id' => $item['id'],
                    'section_id' => $item['section_id'],
                    'parent_work_id' => $item['parent_work_id'],
                    'catalog_item_id' => $item['catalog_item_id'],
                    'normative_rate_code' => $item['normative_rate_code'] ?? null,
                    'base_unit_price' => $item['base_unit_price'] ?? 0,
                    'current_unit_price' => $item['current_unit_price'] ?? 0,
                    'direct_costs' => $item['direct_costs'] ?? 0,
                    'materials_cost' => $item['materials_cost'] ?? 0,
                    'machinery_cost' => $item['machinery_cost'] ?? 0,
                    'labor_cost' => $item['labor_cost'] ?? 0,
                    'overhead_amount' => $item['overhead_amount'] ?? 0,
                    'profit_amount' => $item['profit_amount'] ?? 0,
                    'applied_coefficients' => $item['applied_coefficients'] ?? null,
                    'coefficient_total' => $item['coefficient_total'] ?? null,
                    'custom_resources' => $item['custom_resources'] ?? null,
                    'metadata' => $item['metadata'] ?? null,
                    'is_manual' => $item['is_manual'],
                    'is_not_accounted' => $item['is_not_accounted'],
                ],
            ],
        );
    }

    /**
     * Получить название раздела по ID
     */
    protected function getSectionName(int $sectionId): ?string
    {
        $sections = $this->metadata['sections'] ?? [];
        
        foreach ($sections as $section) {
            if ($section['id'] === $sectionId) {
                return $section['full_section_number'] . '. ' . $section['name'];
            }
        }

        return null;
    }

    /**
     * Получить название родительской работы по ID
     */
    protected function getParentWorkName(?int $parentWorkId): ?string
    {
        if (!$parentWorkId) {
            return null;
        }

        $items = $this->metadata['items'] ?? [];
        
        foreach ($items as $item) {
            if ($item['id'] === $parentWorkId) {
                return $item['name'];
            }
        }

        return null;
    }

    /**
     * Получить метаданные экспорта
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Получить настройки расчета
     */
    public function getCalculationSettings(): ?array
    {
        return $this->metadata['calculation_settings'] ?? null;
    }

    /**
     * Получить структуру разделов
     */
    public function getSections(): array
    {
        return $this->metadata['sections'] ?? [];
    }

    /**
     * Получить все позиции
     */
    public function getItems(): array
    {
        return $this->metadata['items'] ?? [];
    }

    /**
     * Получить тип файла
     */
    public function getType(): string
    {
        return 'prohelper';
    }

    /**
     * Получить приоритет парсера
     */
    public function getPriority(): int
    {
        return 1000; // Highest priority
    }
}
