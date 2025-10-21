<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Validation;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use Illuminate\Support\Facades\Log;

class HierarchyValidator
{
    /**
     * Валидирует иерархию разделов
     *
     * @param EstimateImportDTO $importDTO
     * @return ValidationResult
     */
    public function validate(EstimateImportDTO $importDTO): ValidationResult
    {
        $errors = [];
        $warnings = [];
        
        Log::info('[HierarchyValidator] Starting validation', [
            'sections_count' => count($importDTO->sections),
        ]);
        
        // 1. Проверка корректности нумерации
        $numberingIssues = $this->validateNumbering($importDTO->sections);
        $warnings = array_merge($warnings, $numberingIssues);
        
        // 2. Проверка на "висящие" подразделы
        $orphanIssues = $this->validateOrphans($importDTO->sections);
        $errors = array_merge($errors, $orphanIssues);
        
        // 3. Проверка логичности иерархии
        $hierarchyIssues = $this->validateHierarchyLogic($importDTO->sections);
        $warnings = array_merge($warnings, $hierarchyIssues);
        
        Log::info('[HierarchyValidator] Validation completed', [
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
     * Проверяет корректность нумерации разделов
     */
    private function validateNumbering(array $sections): array
    {
        $warnings = [];
        
        foreach ($sections as $index => $section) {
            $sectionNumber = $section['section_number'] ?? '';
            
            if (empty($sectionNumber)) {
                $warnings[] = "Раздел #{$index}: Отсутствует номер раздела";
                continue;
            }
            
            // Проверяем формат нумерации (1, 1.1, 1.2, 2, 2.1 и т.д.)
            if (!preg_match('/^\d+(\.\d+)*\.?$/', $sectionNumber)) {
                $warnings[] = "Раздел '{$sectionNumber}': Некорректный формат нумерации (ожидается: 1, 1.1, 1.2 и т.д.)";
            }
        }
        
        return $warnings;
    }

    /**
     * Проверяет наличие "висящих" подразделов без родителя
     */
    private function validateOrphans(array $sections): array
    {
        $errors = [];
        
        // Строим карту существующих разделов
        $existingSections = [];
        foreach ($sections as $section) {
            $number = $section['section_number'] ?? '';
            if ($number) {
                $normalized = rtrim($number, '.');
                $existingSections[$normalized] = true;
            }
        }
        
        // Проверяем каждый раздел на наличие родителя
        foreach ($sections as $section) {
            $number = $section['section_number'] ?? '';
            if (empty($number)) {
                continue;
            }
            
            $normalized = rtrim($number, '.');
            $parts = explode('.', $normalized);
            
            // Если это подраздел (уровень > 0)
            if (count($parts) > 1) {
                // Проверяем существование родителя
                array_pop($parts);
                $parentNumber = implode('.', $parts);
                
                if (!isset($existingSections[$parentNumber])) {
                    $errors[] = "Раздел '{$number}': Отсутствует родительский раздел '{$parentNumber}'";
                }
            }
        }
        
        return $errors;
    }

    /**
     * Проверяет логичность иерархии
     */
    private function validateHierarchyLogic(array $sections): array
    {
        $warnings = [];
        
        // Проверяем последовательность уровней
        $previousLevel = -1;
        
        foreach ($sections as $index => $section) {
            $level = $section['level'] ?? 0;
            
            // Проверяем что уровень не увеличивается больше чем на 1
            if ($level > $previousLevel + 1) {
                $warnings[] = "Раздел #{$index}: Пропущен уровень вложенности (предыдущий: {$previousLevel}, текущий: {$level})";
            }
            
            $previousLevel = $level;
        }
        
        return $warnings;
    }
}

