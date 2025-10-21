<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use App\Models\MeasurementUnit;
use Illuminate\Support\Facades\Cache;

class ImportValidationService
{
    private array $standardUnits = [];

    public function __construct()
    {
        $this->loadStandardUnits();
    }

    public function validateStructure(EstimateImportDTO $importDTO): array
    {
        $errors = [];
        
        if ($importDTO->getItemsCount() === 0) {
            $errors[] = 'Не найдено ни одной позиции для импорта';
        }
        
        if ($importDTO->getItemsCount() > 10000) {
            $errors[] = 'Превышен лимит строк для импорта (максимум 10000)';
        }
        
        $emptySections = $this->findEmptySections($importDTO);
        if (!empty($emptySections)) {
            $errors[] = 'Найдены пустые разделы: ' . implode(', ', $emptySections);
        }
        
        return $errors;
    }

    public function validateTotals(EstimateImportDTO $importDTO, ?array $expectedTotals = null): array
    {
        $warnings = [];
        
        if ($expectedTotals === null) {
            return $warnings;
        }
        
        $calculatedTotal = $importDTO->getTotalAmount();
        $expectedTotal = $expectedTotals['total_amount'] ?? null;
        
        if ($expectedTotal !== null && abs($calculatedTotal - $expectedTotal) > 0.01) {
            $diff = abs($calculatedTotal - $expectedTotal);
            $warnings[] = sprintf(
                'Расхождение итоговой суммы: ожидается %.2f, рассчитано %.2f (разница %.2f)',
                $expectedTotal,
                $calculatedTotal,
                $diff
            );
        }
        
        return $warnings;
    }

    public function validateUnits(array $items): array
    {
        $warnings = [];
        $unknownUnits = [];
        
        foreach ($items as $item) {
            $unit = $item['unit'] ?? null;
            
            if (empty($unit)) {
                $warnings[] = sprintf('Строка %d: отсутствует единица измерения', $item['row_number']);
                continue;
            }
            
            if (!$this->isStandardUnit($unit)) {
                $unknownUnits[$unit] = ($unknownUnits[$unit] ?? 0) + 1;
            }
        }
        
        foreach ($unknownUnits as $unit => $count) {
            $warnings[] = sprintf(
                'Нестандартная единица измерения "%s" встречается %d раз(а)',
                $unit,
                $count
            );
        }
        
        return $warnings;
    }

    public function validateNumericValues(array $items): array
    {
        $errors = [];
        
        foreach ($items as $item) {
            $rowNum = $item['row_number'];
            $quantity = $item['quantity'] ?? null;
            $unitPrice = $item['unit_price'] ?? null;
            
            if ($quantity === null || $quantity <= 0) {
                $errors[] = sprintf('Строка %d: некорректное количество (%s)', $rowNum, $quantity ?? 'пусто');
            }
            
            if ($unitPrice === null || $unitPrice < 0) {
                $errors[] = sprintf('Строка %d: некорректная цена (%s)', $rowNum, $unitPrice ?? 'пусто');
            }
            
            if ($quantity > 1000000) {
                $errors[] = sprintf('Строка %d: подозрительно большое количество (%.2f)', $rowNum, $quantity);
            }
            
            if ($unitPrice > 10000000) {
                $errors[] = sprintf('Строка %d: подозрительно высокая цена (%.2f)', $rowNum, $unitPrice);
            }
        }
        
        return $errors;
    }

    public function checkDuplicates(array $items): array
    {
        $warnings = [];
        $names = [];
        
        foreach ($items as $item) {
            $name = mb_strtolower(trim($item['item_name']));
            $rowNum = $item['row_number'];
            
            if (isset($names[$name])) {
                $warnings[] = sprintf(
                    'Возможный дубликат: строки %d и %d имеют одинаковое наименование',
                    $names[$name],
                    $rowNum
                );
            } else {
                $names[$name] = $rowNum;
            }
        }
        
        return $warnings;
    }

    public function generateWarnings(EstimateImportDTO $importDTO): array
    {
        $warnings = [];
        
        $warnings = array_merge($warnings, $this->validateUnits($importDTO->items));
        $warnings = array_merge($warnings, $this->checkDuplicates($importDTO->items));
        
        if ($importDTO->getSectionsCount() === 0) {
            $warnings[] = 'В импортируемом файле не обнаружено разделов. Все позиции будут добавлены в корень сметы.';
        }
        
        $emptyNames = 0;
        foreach ($importDTO->items as $item) {
            if (empty(trim($item['item_name']))) {
                $emptyNames++;
            }
        }
        
        if ($emptyNames > 0) {
            $warnings[] = sprintf('Найдено %d позиций с пустым наименованием', $emptyNames);
        }
        
        return $warnings;
    }

    private function findEmptySections(EstimateImportDTO $importDTO): array
    {
        $emptySections = [];
        $sectionItems = [];
        
        foreach ($importDTO->items as $item) {
            $sectionPath = $item['section_path'] ?? null;
            if ($sectionPath !== null) {
                $sectionItems[$sectionPath] = ($sectionItems[$sectionPath] ?? 0) + 1;
            }
        }
        
        foreach ($importDTO->sections as $section) {
            $sectionNumber = $section['section_number'];
            if (!isset($sectionItems[$sectionNumber]) || $sectionItems[$sectionNumber] === 0) {
                $emptySections[] = $sectionNumber . ' ' . $section['item_name'];
            }
        }
        
        return $emptySections;
    }

    private function loadStandardUnits(): void
    {
        $cacheKey = 'standard_measurement_units';
        
        $this->standardUnits = Cache::remember($cacheKey, now()->addHours(24), function () {
            return MeasurementUnit::pluck('name')->map(function ($name) {
                return mb_strtolower(trim($name));
            })->toArray();
        });
        
        $this->standardUnits = array_merge($this->standardUnits, [
            'шт', 'м', 'м2', 'м3', 'кг', 'т', 'л', 'компл', 'п.м', 'кв.м', 'куб.м',
            'шт.', 'м.', 'кг.', 'т.', 'л.', 'компл.',
        ]);
    }

    private function isStandardUnit(string $unit): bool
    {
        $normalized = mb_strtolower(trim($unit));
        return in_array($normalized, $this->standardUnits, true);
    }
}

