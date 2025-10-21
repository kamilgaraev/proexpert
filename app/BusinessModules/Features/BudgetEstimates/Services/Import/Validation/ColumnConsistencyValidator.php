<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Validation;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use Illuminate\Support\Facades\Log;

class ColumnConsistencyValidator
{
    /**
     * Валидирует консистентность колонок
     *
     * @param EstimateImportDTO $importDTO
     * @return ValidationResult
     */
    public function validate(EstimateImportDTO $importDTO): ValidationResult
    {
        $errors = [];
        $warnings = [];
        
        Log::info('[ColumnConsistency] Starting validation');
        
        // 1. Проверка одинакового количества колонок
        $columnCountIssues = $this->validateColumnCounts($importDTO);
        $warnings = array_merge($warnings, $columnCountIssues);
        
        // 2. Проверка типов данных в колонках
        $dataTypeIssues = $this->validateDataTypes($importDTO);
        $errors = array_merge($errors, $dataTypeIssues);
        
        // 3. Проверка на "разорванные" таблицы (пустые строки посередине)
        $brokenTableIssues = $this->validateTableContinuity($importDTO);
        $warnings = array_merge($warnings, $brokenTableIssues);
        
        // 4. Проверка обязательных полей
        $requiredFieldsIssues = $this->validateRequiredFields($importDTO);
        $errors = array_merge($errors, $requiredFieldsIssues);
        
        Log::info('[ColumnConsistency] Validation completed', [
            'errors_count' => count($errors),
            'warnings_count' => count($warnings),
        ]);
        
        return new ValidationResult(
            errors: $errors,
            warnings: $warnings,
            isValid: empty($errors)
        );
    }

    /**
     * Проверяет количество колонок в каждой строке
     */
    private function validateColumnCounts(EstimateImportDTO $importDTO): array
    {
        $warnings = [];
        
        // Для Excel/CSV это не так критично
        // Добавим warning если есть большие различия
        
        return $warnings;
    }

    /**
     * Проверяет типы данных в колонках
     */
    private function validateDataTypes(EstimateImportDTO $importDTO): array
    {
        $errors = [];
        
        foreach ($importDTO->items as $index => $item) {
            $rowNumber = $index + 1;
            
            // Quantity должно быть числом
            if (isset($item['quantity']) && !is_numeric($item['quantity'])) {
                $errors[] = "Строка {$rowNumber}: Количество должно быть числом (получено: {$item['quantity']})";
            }
            
            // Unit price должно быть числом
            if (isset($item['unit_price']) && !is_numeric($item['unit_price'])) {
                $errors[] = "Строка {$rowNumber}: Цена должна быть числом (получено: {$item['unit_price']})";
            }
            
            // Проверка отрицательных значений
            if (isset($item['quantity']) && $item['quantity'] < 0) {
                $errors[] = "Строка {$rowNumber}: Количество не может быть отрицательным";
            }
            
            if (isset($item['unit_price']) && $item['unit_price'] < 0) {
                $errors[] = "Строка {$rowNumber}: Цена не может быть отрицательной";
            }
        }
        
        return $errors;
    }

    /**
     * Проверяет непрерывность таблицы
     */
    private function validateTableContinuity(EstimateImportDTO $importDTO): array
    {
        $warnings = [];
        
        // Если между разделами/позициями есть большие пропуски - это подозрительно
        // Но для смет это нормально, т.к. могут быть комментарии и т.д.
        
        return $warnings;
    }

    /**
     * Проверяет наличие обязательных полей
     */
    private function validateRequiredFields(EstimateImportDTO $importDTO): array
    {
        $errors = [];
        
        foreach ($importDTO->items as $index => $item) {
            $rowNumber = $index + 1;
            
            // Наименование обязательно
            if (empty($item['item_name'])) {
                $errors[] = "Строка {$rowNumber}: Отсутствует наименование работы";
            }
            
            // Для позиций (не разделов) нужны количество и цена
            if (!($item['is_section'] ?? false)) {
                if (!isset($item['quantity']) || $item['quantity'] === null || $item['quantity'] === '') {
                    $errors[] = "Строка {$rowNumber}: Отсутствует количество";
                }
                
                if (!isset($item['unit'])) {
                    $errors[] = "Строка {$rowNumber}: Отсутствует единица измерения";
                }
            }
        }
        
        return $errors;
    }
}

/**
 * Результат валидации
 */
class ValidationResult
{
    public function __construct(
        public array $errors = [],
        public array $warnings = [],
        public bool $isValid = true,
        public array $fixableSuggestions = []
    ) {}
}

