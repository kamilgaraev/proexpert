<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\GrandSmeta;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet\SpreadsheetNumberParser;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

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
    private array $errors = [];
    private array $components = [];
    private ?float $directSubtotal = null;
    private ?float $machineLaborSubtotal = null;
    private ?float $laborSubtotal = null;
    private array $baseComponents = [];
    private ?float $baseDirectSubtotal = null;
    private array $modernPositions = [];
    private array $indexGroups = [];
    private string $footerScope = '';
    private bool $modernFinalized = false;
    private bool $inSectionSummary = false;
    private bool $modern = false;
    private int $worksheetIndex = 0;
    private string $worksheetName = '';
    private array $sourceAccountingStates = [];

    public function setSourceAccountingStates(array $states): void
    {
        $this->sourceAccountingStates = $states;
    }

    public function setWorksheet(int $index, string $name): void
    {
        $this->worksheetIndex = $index;
        $this->worksheetName = $name;
    }

    public function setLayout(bool $modern): void
    {
        $this->modern = $modern;
    }

    public function processRow(array $rowData, array $mapping, int $rowNumber): void
    {
        if ($this->modern || isset($mapping['base_total_price'])) {
            $this->modern = true;
            $this->processModernRow($rowData, $mapping, $rowNumber);

            return;
        }
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

    private function isPosition(string $posNo, string $name, string $code, array $rowData = [], array $mapping = []): bool
    {
        if (empty($posNo)) return false;

        $lowerName = mb_strtolower($name);
        
        // Исключаем системные строки ресурсов
        if (in_array($lowerName, ['м', 'от', 'зп', 'эм', 'зт', 'от(зт)', 'отм', 'зпм', 'зтм', 'мат'], true)) {
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
        if ($this->modern && $this->currentPosition !== null) {
            $position = $this->currentPosition;
            $completeComponents = isset($this->components['labor'], $this->components['machinery'], $this->components['materials']) && !in_array(null, $this->components, true);
            if ($position->currentTotalAmount === null && ($completeComponents || $this->directSubtotal !== null) && !array_key_exists('overhead_missing', $this->components) && !array_key_exists('profit_missing', $this->components)) {
                $position->currentTotalAmount = round(($this->directSubtotal ?? array_sum($this->components)) + ($position->overheadAmount ?? 0) + ($position->profitAmount ?? 0), 2);
                $position->rawData['total_source'] = 'current_components';
            }
            if ($position->currentTotalAmount !== null && ($position->quantity ?? 0) != 0) {
                $position->unitPrice = ($position->currentTotalAmount - ($position->overheadAmount ?? 0) - ($position->profitAmount ?? 0)) / $position->quantity;
                $position->currentUnitPrice = $position->unitPrice;
            }
            $position->rawData['financial_structure'] = [
                'layout' => 'grand_modern_2020',
                'effective_unit_price' => ($position->quantity ?? 0) != 0 && $position->currentTotalAmount !== null ? $position->currentTotalAmount / $position->quantity : null,
            ];
            $sourceLabor = $this->laborSubtotal;
            if ($sourceLabor === null && isset($this->components['labor']) && ($this->machineLaborSubtotal !== null || ($this->components['machinery'] ?? null) === 0.0)) {
                $sourceLabor = $this->components['labor'] + ($this->machineLaborSubtotal ?? 0);
            }
            if ($sourceLabor !== null) {
                $position->laborCost = $sourceLabor;
                $position->rawData['financial_structure']['labor_cost'] = $sourceLabor;
                $position->rawData['financial_structure']['labor_cost_declared'] = true;
            }
            $position->overheadAmount ??= 0.0;
            $position->profitAmount ??= 0.0;
            if ($this->baseDirectSubtotal !== null && abs(array_sum($this->baseComponents) - $this->baseDirectSubtotal) < 0.000001) {
                foreach (['labor', 'machinery', 'materials'] as $component) {
                    if (!array_key_exists($component, $this->baseComponents)) {
                        $this->baseComponents[$component] = 0.0;
                        $this->components[$component] = 0.0;
                    }
                }
            }
            $position->rawData['component_sources'] = ['base' => $this->baseComponents, 'current' => $this->components, 'base_direct_total' => $this->baseDirectSubtotal];
            if ($this->baseDirectSubtotal !== null && ($position->quantity ?? 0) != 0) {
                $position->baseUnitPrice = $this->baseDirectSubtotal / $position->quantity;
            }
            $this->modernPositions[] = $position;
        }
        $this->components = [];
        $this->directSubtotal = null;
        $this->machineLaborSubtotal = null;
        $this->laborSubtotal = null;
        $this->baseComponents = [];
        $this->baseDirectSubtotal = null;
        $this->currentPosition = null;
    }

    public function getResult(): array
    {
        $this->closeCurrentPosition();
        $this->finalizeModernPositions();
        
        return [
            'items' => $this->items,
            'sections' => $this->sections,
            'footer' => $this->footerData,
            'errors' => $this->errors,
        ];
    }
    
    public function reset(): void
    {
        $this->currentPosition = null;
        $this->items = [];
        $this->sections = [];
        $this->inFooter = false;
        $this->footerData = [];
        $this->errors = [];
        $this->components = [];
        $this->directSubtotal = null;
        $this->machineLaborSubtotal = null;
        $this->laborSubtotal = null;
        $this->baseComponents = [];
        $this->baseDirectSubtotal = null;
        $this->modernPositions = [];
        $this->indexGroups = [];
        $this->footerScope = '';
        $this->modernFinalized = false;
        $this->inSectionSummary = false;
        $this->modern = false;
        $this->sourceAccountingStates = [];
    }

    private function processModernRow(array $data, array $mapping, int $rowNumber): void
    {
        $name = trim((string) ($data[$mapping['name']] ?? ''));
        $label = mb_strtolower(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if ($this->inFooter) {
            $this->processModernFooter($label, $data, $mapping);

            return;
        }
        if ($this->isFooterMarker($name)) {
            $this->closeCurrentPosition();
            $this->inFooter = true;
            $this->processModernFooter($label, $data, $mapping);

            return;
        }
        if (preg_match('/^(?:итоги|всего|итого) по разделу/u', $label) === 1) {
            $this->closeCurrentPosition();
            $this->inSectionSummary = true;

            return;
        }
        if (str_contains($label, 'всего по позиции')) {
            $total = $this->number($data, $mapping, 'total_price');
            if ($this->currentPosition !== null && $total !== null) {
                $this->currentPosition->currentTotalAmount = $total;
                $this->currentPosition->rawData['total_source'] = 'position_subtotal';
            }

            return;
        }
        if ($this->isSection($data, $name)) {
            $this->closeCurrentPosition();
            $this->inSectionSummary = false;
            $dto = $this->mapToDTO($data, $mapping, $rowNumber, true);
            $dto->worksheetIndex = $this->worksheetIndex;
            $dto->worksheetName = $this->worksheetName;
            $dto->rawData['source_identity'] = $this->worksheetIndex.':'.$rowNumber;
            $this->sections[] = $dto;

            return;
        }
        $posNo = trim((string) ($data[$mapping['position_number']] ?? ''));
        $posNo = preg_split('/\s+/u', $posNo)[0] ?? '';
        if (preg_match('/^\d+[а-яa-z-]*$/iu', $posNo) === 1) {
            $this->closeCurrentPosition();
            $this->inSectionSummary = false;
            $this->currentPosition = $this->modernDTO($data, $mapping, $rowNumber);
            $this->currentPosition->sectionNumber = $posNo;
            $this->items[] = $this->currentPosition;

            return;
        }
        if ($this->currentPosition === null || $this->inSectionSummary) {
            return;
        }
        $total = $this->number($data, $mapping, 'total_price');
        if ($label === 'итого по расценке') {
            $this->directSubtotal = $total;
            $this->baseDirectSubtotal = $this->number($data, $mapping, 'base_total_price');

            return;
        }
        if (preg_match('/^(?:в\s*т\.?\s*ч\.?\s*)?отм(?:\(зтм\))?$/u', $label) === 1) {
            $this->machineLaborSubtotal = $total;

            return;
        }
        if ($label === 'фот') {
            $this->laborSubtotal = $total;

            return;
        }
        if (preg_match('/^(?:нр(?:\s|\(|$)|накладные расходы)/u', $label) === 1) {
            $this->currentPosition->overheadAmount = $total;
            if ($total === null) {
                $this->components['overhead_missing'] = null;
            }

            return;
        }
        if (preg_match('/^(?:сп(?:\s|\(|$)|сметная прибыль)/u', $label) === 1) {
            $this->currentPosition->profitAmount = $total;
            if ($total === null) {
                $this->components['profit_missing'] = null;
            }

            return;
        }
        $component = match ($label) {
            'от', 'зп', 'от(зт)' => 'labor',
            'эм' => 'machinery',
            'м', 'мат', 'материалы' => 'materials',
            default => null,
        };
        if ($component !== null) {
            $this->components[$component] = $total;
            $base = $this->number($data, $mapping, 'base_total_price');
            if ($base !== null) {
                $this->baseComponents[$component] = $base;
            }
            $property = match ($component) {
                'labor' => 'laborCost', 'machinery' => 'machineryCost', default => 'materialsCost',
            };
            $this->currentPosition->{$property} = $total;
        }
        if (preg_match('/^(?:в\s*т\.?\s*ч\.?\s*отм|отм|фот|итого|всего|затраты труда)/u', $label) === 1) {
            return;
        }
        $child = $this->modernDTO($data, $mapping, $rowNumber);
        $child->isSubItem = true;
        if (mb_strtoupper(trim((string) ($data[$mapping['position_number']] ?? ''))) === 'Н' && $child->currentTotalAmount !== null) {
            $this->components['unaccounted_resource'] = null;
        }
        $child->rawData['parent_source_identity'] = $this->worksheetIndex.':'.$this->currentPosition->rowNumber;
        if ($component !== null) {
            $child->rawData['component_category'] = $component;
        }
        $this->items[] = $child;
    }

    private function number(array $data, array $mapping, string $field): ?float
    {
        return (new SpreadsheetNumberParser)->parse($data[$mapping[$field] ?? ''] ?? null);
    }

    private function modernDTO(array $data, array $mapping, int $rowNumber): EstimateImportRowDTO
    {
        $dto = $this->mapToDTO($data, $mapping, $rowNumber, false);
        $dto->quantity = $this->number($data, $mapping, 'quantity');
        $dto->quantityTotal = $dto->quantity;
        $dto->quantityCoefficient = $this->number($data, $mapping, 'quantity_coefficient');
        $baseTotal = $this->number($data, $mapping, 'base_total_price');
        $dto->baseUnitPrice = ($dto->quantity ?? 0) != 0 && $baseTotal !== null ? $baseTotal / $dto->quantity : null;
        $dto->priceCoefficient = $this->number($data, $mapping, 'price_coefficient');
        $dto->priceIndex = $this->number($data, $mapping, 'price_index');
        $dto->currentTotalAmount = $this->number($data, $mapping, 'total_price');
        $dto->unitPrice = ($dto->quantity ?? 0) != 0 && $dto->currentTotalAmount !== null ? $dto->currentTotalAmount / $dto->quantity : null;
        $dto->currentUnitPrice = $dto->unitPrice;
        $dto->worksheetIndex = $this->worksheetIndex;
        $dto->worksheetName = $this->worksheetName;
        $dto->rawData['source_identity'] = $this->worksheetIndex.':'.$rowNumber;
        $dto->rawData['base_total_amount'] = $this->number($data, $mapping, 'base_total_price');
        $dto->rawData['source_unit_price'] = $this->number($data, $mapping, 'base_unit_price');
        $dto->rawData['total_source'] = $dto->currentTotalAmount !== null ? 'declared_current_total' : null;
        $dto->isNotAccounted = mb_strtoupper(trim((string) ($data[$mapping['position_number']] ?? ''))) === 'Н' && $dto->currentTotalAmount === null;
        if (isset($this->sourceAccountingStates[$rowNumber])) {
            $dto->isNotAccounted = true;
            $dto->rawData['source_accounting'] = $this->sourceAccountingStates[$rowNumber];
        }
        if (preg_match('/^(?:тц_|тсц|фссц|фсбц|фсрц|01\.)/iu', $dto->code ?? '') === 1) {
            $dto->itemType = 'material';
        }

        return $dto;
    }

    private function processModernFooter(string $label, array $data, array $mapping): void
    {
        if (in_array($label, ['строительные работы', 'монтажные работы'], true)) {
            $this->footerScope = $label;
        }
        $category = match ($label) {
            'эксплуатация машин и механизмов', 'эксплуатация машин' => 'machinery',
            'материалы' => 'materials',
            default => null,
        };
        $index = $this->number($data, $mapping, 'price_index');
        $base = $this->number($data, $mapping, 'base_total_price');
        $total = $this->number($data, $mapping, 'total_price');
        if ($category !== null && $index !== null && $base !== null && $total !== null && $this->footerScope !== '') {
            $this->indexGroups[$category][$this->footerScope] = ['base' => $base, 'index' => $index, 'current' => $total];
        }
        if ($total === null) {
            return;
        }
        if (preg_match('/^(?:(?:в том числе|в\s*т\.?\s*ч\.?|сумма)\s*)?ндс\s*(\d+(?:[.,]\d+)?)?\s*%?$/u', $label, $matches) === 1) {
            $this->footerData['vat_amount'] = $total;
            if (($matches[1] ?? '') !== '') {
                $this->footerData['vat_rate'] = (float) str_replace(',', '.', $matches[1]);
            }

            return;
        }
        if (preg_match('/^(?:всего|итого)(?:\s+(?:по смете|по акту|с учетом доп\. работ и затрат|без ндс|с учетом ндс))?$/u', $label) === 1) {
            $isGross = str_contains($label, 'с учетом ндс') || (isset($this->footerData['vat_amount']) && !str_contains($label, 'без ндс'));
            $this->footerData[$isGross ? 'total_with_vat' : 'total_estimate_cost'] = $total;

            return;
        }
        if ($this->footerScope !== '' && !str_starts_with($label, 'всего ')) {
            return;
        }
        $this->processFooterRow($label, $data, $mapping);
    }

    private function finalizeModernPositions(): void
    {
        if (!$this->modern || $this->modernFinalized) {
            return;
        }
        $this->modernFinalized = true;
        foreach ($this->indexGroups as $category => $groups) {
            $componentName = $category === 'machinery' ? 'машин и механизмов' : 'материалов';
            $indices = array_unique(array_column($groups, 'index'));
            if (count($indices) !== 1) {
                continue;
            }
            $index = (float) reset($indices);
            $parts = [];
            $inactiveParts = [];
            $known = 0.0;
            $actualBase = 0.0;
            foreach ($this->modernPositions as $position) {
                $sources = $position->rawData['component_sources'];
                $base = $sources['base'][$category] ?? null;
                $current = $sources['current'][$category] ?? null;
                if ($category === 'materials' && $position->itemType === 'material' && $sources['base'] === []) {
                    $base = $position->rawData['base_total_amount'];
                    $current = $position->currentTotalAmount;
                }
                if ($base === null) {
                    continue;
                }
                if ($position->isNotAccounted) {
                    if ($current === null) {
                        $inactiveParts[$position->rowNumber] = BigDecimal::of((string) $base)->multipliedBy((string) $index);
                    }
                    continue;
                }
                $actualBase += $base;
                if ($current !== null) {
                    $known += $current;
                } else {
                    $parts[$position->rowNumber] = BigDecimal::of((string) $base)->multipliedBy((string) $index);
                }
            }
            $sourceBase = array_sum(array_column($groups, 'base'));
            if (abs($actualBase - $sourceBase) >= 0.000001) {
                $this->errors[] = ['key' => 'estimate.import_grand_component_mismatch', 'params' => ['component' => $componentName, 'computed' => round($actualBase, 2), 'source' => $sourceBase]];

                continue;
            }
            $target = BigDecimal::zero();
            $groupsValid = true;
            foreach ($groups as $scope => $group) {
                $computed = BigDecimal::of((string) $group['base'])->multipliedBy((string) $index)->toScale(2, RoundingMode::HalfUp);
                if ($computed->compareTo((string) $group['current']) !== 0) {
                    $this->errors[] = ['key' => 'estimate.import_grand_component_mismatch', 'params' => ['component' => $componentName.' ('.$scope.')', 'computed' => $computed->toFloat(), 'source' => $group['current']]];
                    $groupsValid = false;
                }
                $target = $target->plus((string) $group['current']);
            }
            if (!$groupsValid) {
                continue;
            }
            foreach ($this->modernPositions as $position) {
                if (isset($inactiveParts[$position->rowNumber])) {
                    $exact = $inactiveParts[$position->rowNumber];
                    $this->applyIndexedComponent($position, $category, $exact->toScale(2, RoundingMode::HalfUp), $exact, $index, $groups, 'position_half_up_v1');
                }
            }
            if ($parts === []) {
                continue;
            }
            $allocated = [];
            $sum = BigDecimal::zero();
            foreach ($parts as $rowNumber => $exact) {
                $allocated[$rowNumber] = $exact->toScale(2, RoundingMode::Floor);
                $sum = $sum->plus($allocated[$rowNumber]);
            }
            $remainder = $target->minus((string) $known)->minus($sum)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
            if ($remainder < 0 || $remainder > count($parts)) {
                $this->errors[] = ['key' => 'estimate.import_grand_component_mismatch', 'params' => ['component' => $componentName, 'computed' => $known + $sum->toFloat(), 'source' => $target->toFloat()]];

                continue;
            }
            uksort($parts, static fn ($left, $right): int => $parts[$right]->minus($allocated[$right])->compareTo($parts[$left]->minus($allocated[$left])) ?: $left <=> $right);
            foreach (array_keys($parts) as $offset => $rowNumber) {
                if ($offset < $remainder) {
                    $allocated[$rowNumber] = $allocated[$rowNumber]->plus('0.01');
                }
            }
            foreach ($this->modernPositions as $position) {
                if (!isset($allocated[$position->rowNumber])) {
                    continue;
                }
                $this->applyIndexedComponent($position, $category, $allocated[$position->rowNumber], $parts[$position->rowNumber], $index, $groups, 'group_largest_remainder_v1');
            }
        }
        foreach ($this->modernPositions as $position) {
            $sources = $position->rawData['component_sources'];
            if ($position->currentTotalAmount === null && isset($sources['current']['labor'], $sources['current']['machinery'], $sources['current']['materials'])
                && !in_array(null, $sources['current'], true) && $sources['base_direct_total'] !== null
                && abs(array_sum($sources['base']) - $sources['base_direct_total']) < 0.000001) {
                $position->currentTotalAmount = round(array_sum($sources['current']) + $position->overheadAmount + $position->profitAmount, 2);
                $position->rawData['total_source'] = 'indexed_current_components';
            }
            if ($position->currentTotalAmount === null) {
                $this->errors[] = ['key' => 'estimate.import_grand_missing_current_total', 'params' => ['worksheet' => $this->worksheetName, 'row' => $position->rowNumber]];
            } elseif (($position->quantity ?? 0) != 0) {
                $position->unitPrice = ($position->currentTotalAmount - $position->overheadAmount - $position->profitAmount) / $position->quantity;
                $position->currentUnitPrice = $position->unitPrice;
                $position->rawData['financial_structure']['effective_unit_price'] = $position->currentTotalAmount / $position->quantity;
            }
        }
        $parents = [];
        foreach ($this->modernPositions as $position) {
            $parents[$position->rawData['source_identity']] = $position;
        }
        foreach ($this->items as $child) {
            $parent = $parents[$child->rawData['parent_source_identity'] ?? ''] ?? null;
            $category = $child->rawData['component_category'] ?? null;
            if ($parent === null || $category === null) {
                continue;
            }
            if ($parent->isNotAccounted) {
                $child->isNotAccounted = true;
            }
            if ($child->currentTotalAmount === null && isset($parent->rawData['index_provenance'][$category])) {
                $child->currentTotalAmount = $parent->rawData['component_sources']['current'][$category];
                $child->rawData['total_source'] = 'parent_indexed_component';
                $child->rawData['index_provenance'][$category] = $parent->rawData['index_provenance'][$category];
                if (($child->quantity ?? 0) != 0) {
                    $child->currentUnitPrice = $child->currentTotalAmount / $child->quantity;
                    $child->unitPrice = $child->currentUnitPrice;
                }
            }
        }
    }

    private function applyIndexedComponent(EstimateImportRowDTO $position, string $category, BigDecimal $amount, BigDecimal $exact, float $index, array $groups, string $strategy): void
    {
        if ($category === 'materials' && $position->itemType === 'material' && $position->rawData['component_sources']['base'] === []) {
            $position->currentTotalAmount = $amount->toFloat();
        } else {
            $position->rawData['component_sources']['current'][$category] = $amount->toFloat();
            $property = $category === 'machinery' ? 'machineryCost' : 'materialsCost';
            $position->{$property} = $amount->toFloat();
        }
        $position->rawData['index_provenance'][$category] = [
            'groups' => $groups,
            'index' => $index,
            'exact_amount' => (string) $exact,
            'rounding_strategy' => $strategy,
            'rounding_adjustment' => $amount->minus($exact->toScale(2, RoundingMode::HalfUp))->toFloat(),
        ];
    }

    private function processFooterRow(string $name, array $rowData, array $mapping): void
    {
        if (empty(trim($name))) {
            return;
        }
        
        $val = $this->parseFloat($rowData[$mapping['total_price'] ?? ''] ?? 0);
        $cleanName = mb_strtolower(trim($name));

        if (str_starts_with($cleanName, 'итого прямые затраты') || str_starts_with($cleanName, 'прямые затраты')) {
            $this->footerData['direct_costs'] = $val; // ⭐ Не суммируем, а берем последнее значение (итог)
        } elseif (str_starts_with($cleanName, 'оплата труда рабочих') || $cleanName === 'оплата труда' || str_contains($cleanName, 'фот')) {
            $this->footerData['labor_cost'] = $val;
        } elseif (str_starts_with($cleanName, 'материалы') || str_contains($cleanName, 'итого материалы')) {
            $this->footerData['materials_cost'] = $val;
        } elseif (str_contains($cleanName, 'накладные расходы') || str_contains($cleanName, 'итого накладные') || str_starts_with($cleanName, 'нр')) {
            $this->footerData['overhead_cost'] = $val;
        } elseif (str_starts_with($cleanName, 'справочно')) {
            return;
        } elseif (str_contains($cleanName, 'сметная прибыль') || str_contains($cleanName, 'итого сметная') || preg_match('/^сп(?:\s|\(|$)/u', $cleanName)) {
            $this->footerData['profit_cost'] = $val;
        } elseif (str_starts_with($cleanName, 'оборудование')) {
            $this->footerData['equipment_cost'] = $val;
        } elseif (str_contains($cleanName, 'всего по смете') || str_contains($cleanName, 'итого по смете') || str_contains($cleanName, 'всего по акту')) {
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
        $basePrice = $this->parseFloat($data[$mapping['base_unit_price'] ?? ''] ?? 0);
        $priceIndex = $this->parseFloat($data[$mapping['price_index'] ?? ''] ?? 0);
        $currentPrice = $this->parseFloat($data[$mapping['current_unit_price'] ?? ''] ?? 0);
        $price = $currentPrice !== 0.0
            ? $currentPrice
            : $this->parseFloat($data[$mapping['unit_price'] ?? ''] ?? 0);
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

        $unit = (string)($data[$mapping['unit'] ?? ''] ?? '');

        // Государственные сметные нормы (ГЭСН, ГЭСНм, ГЭСНп, ГЭСНр, ГЭСНмр и т.д.) — это всегда работы.
        // Проверяем в первую очередь, чтобы AI-классификация не могла переопределить тип.
        if (preg_match('/^гэсн/ui', $lowerCode)) {
            $itemType = 'work';
        } else {
            $isLabor = str_contains($lowerName, 'труд') ||
                       str_contains($lowerName, 'от(') ||
                       str_contains($lowerName, 'зт(') ||
                       str_contains($lowerName, 'разряд') ||
                       str_contains($lowerName, 'зарплата') ||
                       str_starts_with($lowerCode, 'от') ||
                       str_starts_with($lowerCode, 'зп') ||
                       str_starts_with($lowerCode, '4-100') ||
                       trim($unit) === 'чел.-ч' ||
                       trim($unit) === 'чел-ч';

            if ($isLabor) {
                $itemType = 'labor';
            } elseif (str_contains($lowerName, 'маш.') || str_contains($lowerName, 'механизм') || str_starts_with($lowerCode, 'эм') || str_starts_with($lowerCode, 'фсэм')) {
                $itemType = 'machinery';
            } elseif (str_contains($lowerName, 'материал') || preg_match('/^(01\.|с\b|тсц|фссц|фсбц|фсрц|прайс)/u', $lowerCode)) {
                $itemType = 'material';
            }
        }

        return new EstimateImportRowDTO(
            rowNumber: $rowNumber,
            sectionNumber: $sectionNum,
            itemName: $name,
            unit: $unit,
            quantity: $qty != 0.0 ? $qty : null,
            unitPrice: $price != 0.0 ? $price : null,
            code: $code,
            isSection: $isSection,
            itemType: $itemType,
            baseUnitPrice: $basePrice != 0.0 ? $basePrice : null,
            priceIndex: $priceIndex != 0.0 ? $priceIndex : null,
            currentUnitPrice: $currentPrice != 0.0 ? $currentPrice : null,
            currentTotalAmount: $total != 0.0 ? $total : null,
            rawData: $data + ['source_identity' => $this->worksheetIndex.':'.$rowNumber],
            worksheetIndex: $this->worksheetIndex,
            worksheetName: $this->worksheetName,
        );
    }

    private function parseFloat(mixed $value, bool $isQuantity = false, bool $takeLast = false): float
    {
        if (is_numeric($value)) return (float)$value;
        if (empty($value)) return 0.0;

        // Clean up common spaces and convert comma to dot
        $clean = str_replace([' ', "\xc2\xa0", "\xa0", "\r", "\n", "\t"], '', (string)$value);
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
