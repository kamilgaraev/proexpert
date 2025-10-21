<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\HeaderDetection;

abstract class AbstractHeaderDetector implements HeaderDetectorInterface
{
    protected float $weight = 1.0;
    protected int $maxRowsToScan = 50;

    public function getWeight(): float
    {
        return $this->weight;
    }

    public function selectBest(array $candidates): ?array
    {
        if (empty($candidates)) {
            return null;
        }

        usort($candidates, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        
        return $candidates[0];
    }

    /**
     * Извлекает значения строки из worksheet
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @param int $row
     * @return array Массив значений ячеек с ключами - буквами колонок
     */
    protected function getRowValues($sheet, int $row): array
    {
        $values = [];
        $highestColumn = $sheet->getHighestColumn();
        
        foreach (range('A', $highestColumn) as $col) {
            $cell = $sheet->getCell($col . $row);
            $value = $cell->getValue();
            
            if ($value !== null && trim((string)$value) !== '') {
                $values[$col] = trim((string)$value);
            }
        }
        
        return $values;
    }

    /**
     * Подсчитывает непустые значения в строке
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @param int $row
     * @return int
     */
    protected function countFilledCells($sheet, int $row): int
    {
        return count($this->getRowValues($sheet, $row));
    }

    /**
     * Проверяет является ли строка служебной информацией
     *
     * @param array $rowValues
     * @return bool
     */
    protected function isServiceInfo(array $rowValues): bool
    {
        if (empty($rowValues)) {
            return true;
        }

        $text = mb_strtolower(implode(' ', $rowValues));
        
        $servicePatterns = [
            'приказ',
            'минстрой',
            'гранд-смета',
            'версия программного продукта',
            'редакции сметных',
            'составлен в текущих',
            'основание:',
        ];

        foreach ($servicePatterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }

        return false;
    }
}

