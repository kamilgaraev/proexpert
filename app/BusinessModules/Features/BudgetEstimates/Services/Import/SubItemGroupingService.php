<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use Illuminate\Support\Facades\Log;

class SubItemGroupingService
{
    private const SUB_ITEM_KEYWORDS = [
        'material'  => ['материал', 'мат.', 'материалы', 'м:'],
        'machinery' => ['механизм', 'маш.', 'эм:', 'эм.', 'машины и механизмы', 'экскаватор', 'кран', 'бульдозер'],
        'labor'     => ['от:', 'от.', 'оплата труда', 'труд рабочих', 'зп.', 'з/п'],
    ];

    private const RESOURCE_CODE_PATTERNS = [
        'material'  => '/^(С|ТСЦ|01\.|ФССЦ|СМТ|ТСН|ресурс)/iu',
        'machinery' => '/^(машины|маш\.|91\.|мех\.|ЭМ)/iu',
        'labor'     => '/^(ОТ|оплата|зарплата|ФОТ)/iu',
    ];

    public function groupItems(array $flatRows, array &$state = []): array
    {
        $result           = [];
        $lastWorkIndex    = ($state['carry_over'] ?? false) ? 'prev' : null;
        $lastWorkLevel    = $state['last_work_level'] ?? null;

        foreach ($flatRows as $idx => $row) {
            if ($row['is_section'] ?? false) {
                $result[]      = $row;
                $lastWorkIndex = null;
                $lastWorkLevel = null;
                $state['carry_over'] = false;
                continue;
            }

            $itemType = $row['item_type'] ?? 'work';
            $level    = (int)($row['level'] ?? 0);

            if ($this->isSubItem($row, $lastWorkLevel)) {
                if ($lastWorkIndex !== null) {
                    $row['_parent_index'] = $lastWorkIndex;
                    $row['item_type']     = $this->resolveSubItemType($row);
                    $row['is_sub_item']   = true;
                    $itemNameLog = $row['name'] ?? $row['item_name'] ?? 'unknown';
                    Log::info("[SubItemGrouper] Row '{$itemNameLog}' grouped under parent idx={$lastWorkIndex} as {$row['item_type']}");
                }
            } else {
                $lastWorkIndex = count($result);
                $lastWorkLevel = $level;
                $row['is_sub_item'] = false;
                $state['carry_over'] = true;
                $state['last_work_level'] = $level;
            }

            $result[] = $row;
        }

        return $result;
    }

    private function isSubItem(array $row, ?int $parentLevel): bool
    {
        // ⭐ If already flagged as sub-item (e.g. by specialized parser), trust it.
        if (!empty($row['is_sub_item'])) {
            return true;
        }

        // Если у позиции есть явный номер (например "10", "15О", но НЕ "1.1"), 
        // то это 100% самостоятельная (Main) позиция.
        $position = trim((string)($row['position_number'] ?? ''));
        if ($position !== '') {
            // Пропускаем дробные номера (например 1.1, 10.5), так как они часто означают подпункты
            if (!preg_match('/^\d+\.\d+$/', $position)) {
                return false;
            }
        }

        $type = $row['item_type'] ?? 'work';
        if (in_array($type, ['material', 'machinery', 'equipment', 'labor'], true)) {
            return true;
        }

        $level = (int)($row['level'] ?? 0);

        if ($parentLevel !== null && $level > $parentLevel) {
            return true;
        }

        $name = mb_strtolower(trim((string)($row['name'] ?? $row['item_name'] ?? '')));
        $code = mb_strtolower(trim((string)($row['normative_rate_code'] ?? $row['code'] ?? '')));

        foreach (self::SUB_ITEM_KEYWORDS as $keywords) {
            foreach ($keywords as $kw) {
                if (str_starts_with($name, $kw) || str_starts_with($name, mb_strtolower($kw) . ' ')) {
                    return true;
                }
            }
        }

        foreach (self::RESOURCE_CODE_PATTERNS as $pattern) {
            if ($code !== '' && preg_match($pattern, $code)) {
                return true;
            }
        }

        return false;
    }

    private function resolveSubItemType(array $row): string
    {
        $name = mb_strtolower(trim((string)($row['name'] ?? $row['item_name'] ?? '')));
        $code = (string)($row['normative_rate_code'] ?? $row['code'] ?? '');

        foreach (self::RESOURCE_CODE_PATTERNS as $type => $pattern) {
            if ($code !== '' && preg_match($pattern, $code)) {
                return $type;
            }
        }

        foreach (self::SUB_ITEM_KEYWORDS as $type => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($name, mb_strtolower($kw))) {
                    return $type;
                }
            }
        }

        if (isset($row['item_type']) && in_array($row['item_type'], ['material', 'machinery', 'equipment', 'labor'])) {
            return $row['item_type'];
        }

        return 'material';
    }

    public function assignParentWorkIds(array $grouped, array &$insertedIds): array
    {
        foreach ($grouped as &$row) {
            if (!empty($row['is_sub_item']) && isset($row['_parent_index'])) {
                $parentIdx = $row['_parent_index'];
                if (isset($insertedIds[$parentIdx])) {
                    $row['parent_work_id'] = $insertedIds[$parentIdx];
                }
                unset($row['_parent_index'], $row['is_sub_item']);
            } else {
                unset($row['_parent_index'], $row['is_sub_item']);
            }
        }

        return $grouped;
    }
}
