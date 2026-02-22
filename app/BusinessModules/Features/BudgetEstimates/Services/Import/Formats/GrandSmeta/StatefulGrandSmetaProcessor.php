<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\GrandSmeta;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use Illuminate\Support\Facades\Log;

/**
 * Handles hierarchical parsing of GrandSmeta (LSR 421) files.
 * Tracks "State" to group resources under positions.
 */
class StatefulGrandSmetaProcessor
{
    private ?EstimateImportRowDTO $currentPosition = null;
    private array $items = [];
    private array $sections = [];
    private bool $inFooter = false;
    private array $footerData = [];

    public function processRow(array $rowData, array $mapping, int $rowNumber): void
    {
        $name = trim((string)($rowData[$mapping['name'] ?? ''] ?? ''));
        $posNo = trim((string)($rowData[$mapping['position_number'] ?? ''] ?? ''));
        $code = trim((string)($rowData[$mapping['code'] ?? ''] ?? ''));
        
        // Clean multi-line posNo (common in some GS forms where "2 \n O" is in one cell)
        $posNo = trim(str_replace(["\r", "\n", "\xc2\xa0"], ' ', $posNo));
        if (!empty($posNo)) {
            $parts = explode(' ', $posNo);
            $posNo = trim($parts[0]);
        }

        if ($this->inFooter) {
            $this->processFooterRow($name, $rowData, $mapping);
            return;
        }

        $nameLower = mb_strtolower($name);
        if (str_contains($nameLower, 'всего по позиции')) {
            if ($this->currentPosition) {
                $money = $this->extractMoney($rowData, $mapping);
                $this->currentPosition->unitPrice = $money['unit_price'] !== 0.0 ? $money['unit_price'] : $this->currentPosition->unitPrice;
                $this->currentPosition->currentTotalAmount = $money['total_price'] !== 0.0 ? $money['total_price'] : $this->currentPosition->currentTotalAmount;
            }
            $this->closeCurrentPosition();
            return;
        }

        if ($this->isFooterMarker($name)) {
            $this->inFooter = true;
            $this->closeCurrentPosition();
            return;
        }

        // 2. Identify Row Type
        $isSection = $this->isSection($rowData, $name);
        
        // Позиция — это не раздел, и у нее есть номер, отличный от текущего
        $isPosition = !$isSection && $this->isPosition($posNo, $name, $code, $rowData, $mapping);
        
        // Ресурс — это если это не раздел и не новая позиция
        $isResource = !$isSection && !$isPosition && $this->isResource($rowData, $mapping);

        if ($isSection) {
            $this->closeCurrentPosition();
            $this->sections[] = $this->mapToDTO($rowData, $mapping, $rowNumber, true);
            return;
        }

        if ($isPosition) {
            $this->closeCurrentPosition();
            $this->currentPosition = $this->mapToDTO($rowData, $mapping, $rowNumber, false);
            // Ensure posNo is the cleaned one
            $this->currentPosition->sectionNumber = $posNo;
            $this->items[] = $this->currentPosition;
            return;
        }

        // 3. Handle Resource/SubItem
        if ($this->currentPosition && !$isSection && !$isPosition) {
            $cleanName = mb_strtolower($name);
            
            // Обработка "Всего по позиции" - это финализация данных текущей позиции
            if (str_contains($cleanName, 'всего по позиции')) {
                $money = $this->extractMoney($rowData, $mapping);
                $total = $money['total_price'] !== 0.0 ? $money['total_price'] : $money['unit_price'];
                if ($total !== 0.0) {
                    $this->currentPosition->currentTotalAmount = $total;
                }
                $this->closeCurrentPosition();
                return;
            }

            // Is it an informational row for NR/SP?
            if (str_starts_with($cleanName, 'нр ') || str_starts_with($cleanName, 'нр(') || str_starts_with($cleanName, 'накладные')) {
                $money = $this->extractMoney($rowData, $mapping);
                $amt = $money['total_price'] !== 0.0 ? $money['total_price'] : $money['unit_price'];
                if ($amt === 0.0) {
                    $amt = $this->parseFloat($name, false, true); // takeLast = true для НР
                }
                $this->currentPosition->overheadAmount = ($this->currentPosition->overheadAmount ?? 0) + $amt;
                return;
            }

            if (str_starts_with($cleanName, 'сп ') || str_starts_with($cleanName, 'сп(') || str_starts_with($cleanName, 'сметная прибыль')) {
                $money = $this->extractMoney($rowData, $mapping);
                $amt = $money['total_price'] !== 0.0 ? $money['total_price'] : $money['unit_price'];
                if ($amt === 0.0) {
                    $amt = $this->parseFloat($name, false, true); // takeLast = true для СП
                }
                $this->currentPosition->profitAmount = ($this->currentPosition->profitAmount ?? 0) + $amt;
                return;
            }
            
            if (str_starts_with($cleanName, 'фот ') || str_starts_with($cleanName, 'оплата труда')) {
                 return; // just informational
            }

            $subItemDTO = $this->mapToDTO($rowData, $mapping, $rowNumber, false);
            $subItemDTO->isSubItem = true;
            $this->items[] = $subItemDTO;
            return;
        }
    }

    private function isResource(array $data, array $mapping): bool
    {
        $name = mb_strtolower(trim((string)($data[$mapping['name'] ?? ''] ?? '')));
        $unit = mb_strtolower(trim((string)($data[$mapping['unit'] ?? ''] ?? '')));
        $code = mb_strtolower(trim((string)($data[$mapping['code'] ?? ''] ?? '')));
        
        // Exclude NR and SP from being resources even if unit is %
        if (str_starts_with($name, 'нр ') || str_starts_with($name, 'нр(') || str_starts_with($name, 'накладные')) return false;
        if (str_starts_with($name, 'сп ') || str_starts_with($name, 'сп(') || str_starts_with($name, 'сметная прибыль')) return false;

        if (str_contains($name, 'вспомогательные') && str_contains($name, 'ресурсы')) {
            return true;
        }

        if (in_array($name, ['м', 'от', 'зп', 'эм', 'зт', 'от(зт)'], true)) {
            return true;
        }

        // Specific GrandSmeta codes for materials and labor
        if (preg_match('/^(01\.|ТСЦ|ФССЦ|ФСБЦ|ФСРЦ|ОТ|ЗП|ЗТ|ЭМ|ФСЭМ)/ui', $code)) {
            return true;
        }

        if (empty($unit)) return false;

        $resourceUnits = ['чел.-ч', 'чел-ч', 'маш.-ч', 'квт-ч', '%'];
        foreach ($resourceUnits as $ru) {
            if ($unit === $ru || str_starts_with($unit, $ru)) return true;
        }

        return false;
    }

    private function isPosition(string $posNo, string $name, string $code, array $rowData = [], array $mapping = []): bool
    {
        if (empty($posNo)) return false;

        $lowerName = mb_strtolower($name);
        
        // Исключаем системные строки ресурсов
        if (in_array($lowerName, ['м', 'от', 'зп', 'эм', 'зт', 'от(зт)', 'отм', 'зпм', 'мат'], true)) {
            return false;
        }

        // Exclude fractional numbers like "1.1", "70.1" which are sub-items
        if (preg_match('/^\d+\.\d+$/', $posNo)) {
            return false;
        }

        // Если номер такой же, как у текущей позиции — это не новая позиция, а ее продолжение (ресурс)
        if ($this->currentPosition && $this->currentPosition->sectionNumber === $posNo) {
            return false;
        }

        // Numbers like "1", "2", "15А", "4О", "15-2"
        if (preg_match('/^[\d\-]+[А-ЯA-Zа-яa-z]*$/ui', $posNo)) {
            return true;
        }

        return false;
    }

    private function extractMoney(array $data, array $mapping): array
    {
        return [
            'unit_price' => $this->parseFloat($data[$mapping['unit_price'] ?? ''] ?? 0),
            'total_price' => $this->parseFloat($data[$mapping['total_price'] ?? ''] ?? 0)
        ];
    }

    private function closeCurrentPosition(): void
    {
        $this->currentPosition = null;
    }

    public function getResult(): array
    {
        $this->closeCurrentPosition();
        
        return [
            'items' => $this->items,
            'sections' => $this->sections,
            'footer' => $this->footerData
        ];
    }
    
    public function reset(): void
    {
        $this->currentPosition = null;
        $this->items = [];
        $this->sections = [];
        $this->inFooter = false;
        $this->footerData = [];
    }

    private function processFooterRow(string $name, array $rowData, array $mapping): void
    {
        if (empty(trim($name))) {
            return;
        }
        
        $val = $this->parseFloat($rowData[$mapping['total_price'] ?? ''] ?? 0);
        $cleanName = mb_strtolower(trim($name));
        
        if (str_starts_with($cleanName, 'итого прямые затраты')) {
            $this->footerData['direct_costs'] = $val;
        } elseif (str_starts_with($cleanName, 'оплата труда рабочих') || $cleanName === 'оплата труда') {
            $this->footerData['labor_cost'] = ($this->footerData['labor_cost'] ?? 0) + $val;
        } elseif (str_starts_with($cleanName, 'материалы')) {
            $this->footerData['materials_cost'] = ($this->footerData['materials_cost'] ?? 0) + $val;
        } elseif (str_starts_with($cleanName, 'накладные расходы') || str_starts_with($cleanName, 'итого накладные')) {
            $this->footerData['overhead_cost'] = $val;
        } elseif (str_starts_with($cleanName, 'сметная прибыль') || str_starts_with($cleanName, 'итого сметная')) {
            $this->footerData['profit_cost'] = $val;
        } elseif (str_starts_with($cleanName, 'оборудование')) {
            $this->footerData['equipment_cost'] = ($this->footerData['equipment_cost'] ?? 0) + $val;
        } elseif (str_starts_with($cleanName, 'всего по смете')) {
            $this->footerData['total_estimate_cost'] = $val;
        }
    }

    private function isSection(array $rowData, string $name): bool
    {
        $sectionPattern = '/^(Раздел|Смета|Объект|Глава|Этап|Комплекс|Локальный|I+|V+|X+)\b/iu';
        
        // Сначала проверяем классическое имя (из маппинга)
        $cleanName = trim($name);
        if (!empty($cleanName)) {
            if (preg_match($sectionPattern, $cleanName)) {
                return true;
            }
        }

        // В GrandSmeta заголовки разделов могут быть в объединенных ячейках (A или B)
        // Пройдемся по первым 3 колонкам, чтобы найти "Раздел ..."
        $count = 0;
        foreach ($rowData as $val) {
            $val = trim((string)$val);
            if (!empty($val)) {
                if (preg_match($sectionPattern, $val)) {
                    return true;
                }
            }
            if (++$count > 3) break;
        }

        return false;
    }

    private function isFooterMarker(string $name): bool
    {
        $nameLower = mb_strtolower(trim($name));
        
        if (str_contains($nameLower, 'всего по позиции')) {
            return false;
        }

        return str_starts_with($nameLower, 'итоги по смете') || 
               str_starts_with($nameLower, 'итоги по акту') ||
               str_starts_with($nameLower, 'согласовано') ||
               $nameLower === 'всего по смете';
    }

    private function mapToDTO(array $data, array $mapping, int $rowNumber, bool $isSection): EstimateImportRowDTO
    {
        $qty = $this->parseFloat($data[$mapping['quantity'] ?? ''] ?? 0, true);
        $price = $this->parseFloat($data[$mapping['unit_price'] ?? ''] ?? 0);
        $total = $this->parseFloat($data[$mapping['total_price'] ?? ''] ?? 0);
        $name = trim((string)($data[$mapping['name'] ?? ''] ?? ''));
        $code = trim((string)($data[$mapping['code'] ?? ''] ?? ''));
        $sectionNum = trim((string)($data[$mapping['position_number'] ?? ''] ?? ''));

        if ($isSection) {
            $sectionFullText = '';
            if (empty($name)) {
                $count = 0;
                foreach ($data as $val) {
                    $val = trim((string)$val);
                    if (!empty($val) && preg_match('/^(Раздел|Смета|Объект|Глава|Этап|Комплекс|Локальный|I+|V+|X+)\b/iu', $val)) {
                        $sectionFullText = $val;
                        break;
                    }
                    if (++$count > 3) break;
                }
            } else {
                $sectionFullText = $name;
            }
            
            if (!empty($sectionFullText)) {
                if (preg_match('/^(?:Раздел|Смета)\s+(\d+(?:\.\d+)*)[.\s]+(.*)$/ui', $sectionFullText, $m)) {
                    $sectionNum = $m[1];
                    $name = trim($m[2]) ?: $sectionFullText;
                } else {
                    $name = $sectionFullText;
                    if ($sectionNum === $sectionFullText) {
                        $sectionNum = ''; 
                    }
                }
            }
        }

        // In GrandSmeta unit price column might be empty if price is only in total
        if (($price === null || $price === 0.0) && $qty != 0 && $total != 0) {
            $price = $total / $qty;
        }

        // Detect Item Type
        $itemType = 'work';
        $lowerName = mb_strtolower($name);
        $lowerCode = mb_strtolower($code);

        if (str_contains($lowerName, 'труд') || str_contains($lowerName, 'от(') || str_starts_with($lowerCode, 'от')) {
            $itemType = 'labor';
        } elseif (str_contains($lowerName, 'маш.') || str_contains($lowerName, 'механизм') || str_starts_with($lowerCode, 'эм') || str_starts_with($lowerCode, 'фсэм')) {
            $itemType = 'machinery';
        } elseif (str_contains($lowerName, 'материал') || preg_match('/^(01\.|с\b|тсц|фссц|фсбц|фсрц|прайс)/u', $lowerCode)) {
            $itemType = 'material';
        }

        return new EstimateImportRowDTO(
            rowNumber: $rowNumber,
            sectionNumber: $sectionNum,
            itemName: $name,
            unit: (string)($data[$mapping['unit'] ?? ''] ?? ''),
            quantity: $qty != 0.0 ? $qty : null,
            unitPrice: $price != 0.0 ? $price : null,
            code: $code,
            isSection: $isSection,
            itemType: $itemType,
            currentTotalAmount: $total != 0.0 ? $total : null,
            rawData: $data
        );
    }

    private function parseFloat(mixed $value, bool $isQuantity = false, bool $takeLast = false): float
    {
        if (is_numeric($value)) return (float)$value;
        if (empty($value)) return 0.0;

        // Clean up common spaces and convert comma to dot
        $clean = str_replace([' ', "\xc2\xa0"], '', (string)$value);
        $clean = str_replace(',', '.', $clean);
        
        // If it's a quantity column, we need to be careful with "100 м" or "Объем=60/100"
        if ($isQuantity) {
            // Check for explicit formulas like "=60/100" or just "60/100"
            if (preg_match('/(?:^|=)(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)/', $clean, $matches)) {
                 $num = (float)$matches[1];
                 $den = (float)$matches[2];
                 if ($den > 0) return $num / $den;
            }
            
            if (preg_match('/[а-яА-Яa-zA-Z]/u', $clean) && !str_starts_with($clean, '=')) {
                 if (preg_match('/^-?\d+(?:\.\d+)?\b/', $clean, $matches)) {
                       return (float)$matches[0];
                 }
                 return 0.0;
            }
            
            if (preg_match('/^-?\d+(\.\d+)?$/', $clean, $matches)) {
                return (float)$matches[0];
            }
        } else {
            // Для денежных полей в GrandSmeta может быть мусор (проценты и т.д.)
            // Используем REGEX для поиска всех чисел с опциональным минусом
            if (preg_match_all('/-?\d+(?:\.\d+)?/', (string)$clean, $matches)) {
                $found = $matches[0];
                if ($takeLast) {
                    return (float)end($found);
                }
                return (float)$found[0];
            }
        }

        return 0.0;
    }
}
