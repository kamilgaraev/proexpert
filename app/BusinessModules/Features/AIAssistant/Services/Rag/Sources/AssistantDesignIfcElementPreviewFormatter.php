<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

final class AssistantDesignIfcElementPreviewFormatter
{
    public const COVERAGE_MARKER = 'Структурное описание IFC-элемента.';

    public const MAX_CONTENT_CHARS = 1100;

    public const MAX_PROPERTIES_BYTES = 65536;

    private const MAX_MATERIALS = 8;

    private const MAX_MATERIALS_TO_INSPECT = 32;

    private const MAX_QUANTITY_GROUPS = 5;

    private const MAX_QUANTITY_GROUPS_TO_INSPECT = 12;

    private const MAX_QUANTITIES_PER_GROUP = 8;

    private const MAX_QUANTITIES_TO_INSPECT_PER_GROUP = 32;

    private const MAX_VALUE_CHARS = 120;

    public function format(array $element, array $properties): string
    {
        $lines = [self::COVERAGE_MARKER];

        foreach ([
            'Тип' => $element['category'] ?? null,
            'Название' => $element['name'] ?? null,
            'Express ID' => $element['express_id'] ?? null,
            'Global ID' => $element['global_id'] ?? null,
        ] as $label => $value) {
            $text = $this->scalarText($value, self::MAX_VALUE_CHARS);
            if ($text !== null) {
                $lines[] = $label.': '.$text;
            }
        }

        $materials = $this->materials($properties['materials'] ?? null);
        if ($materials !== []) {
            $lines[] = 'Материалы из IFC: '.implode('; ', $materials);
        }

        $quantities = $this->quantities($properties['quantities'] ?? null);
        if ($quantities !== []) {
            $lines[] = 'Извлечённые значения количества из IFC (без пересчёта единиц):';
            array_push($lines, ...$quantities);
        }

        return mb_substr(implode("\n", $lines), 0, self::MAX_CONTENT_CHARS, 'UTF-8');
    }

    public function safeName(mixed $value): ?string
    {
        return $this->scalarText($value, 180);
    }

    /** @return array<int, string> */
    private function materials(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $materials = [];
        $inspected = 0;
        foreach ($value as $material) {
            if ($inspected++ >= self::MAX_MATERIALS_TO_INSPECT) {
                break;
            }
            $text = $this->scalarText($material, self::MAX_VALUE_CHARS);
            if ($text !== null) {
                $materials[] = $text;
            }
            if (count($materials) >= self::MAX_MATERIALS) {
                break;
            }
        }

        return $materials;
    }

    /** @return array<int, string> */
    private function quantities(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $lines = [];
        $groups = 0;
        $inspectedGroups = 0;
        foreach ($value as $group => $values) {
            if ($inspectedGroups++ >= self::MAX_QUANTITY_GROUPS_TO_INSPECT) {
                break;
            }
            if (! is_array($values)) {
                continue;
            }

            $groupName = $this->scalarText($group, 80);
            $items = [];
            $inspectedItems = 0;
            foreach ($values as $name => $quantity) {
                if ($inspectedItems++ >= self::MAX_QUANTITIES_TO_INSPECT_PER_GROUP) {
                    break;
                }
                $nameText = $this->scalarText($name, 80);
                $quantityText = $this->scalarText($quantity, self::MAX_VALUE_CHARS);
                if ($nameText !== null && $quantityText !== null) {
                    $items[] = $nameText.' = '.$quantityText;
                }
                if (count($items) >= self::MAX_QUANTITIES_PER_GROUP) {
                    break;
                }
            }

            if ($items !== []) {
                $lines[] = ($groupName !== null ? $groupName.': ' : '').implode('; ', $items);
                $groups++;
            }
            if ($groups >= self::MAX_QUANTITY_GROUPS) {
                break;
            }
        }

        return $lines;
    }

    private function scalarText(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value)) {
            return null;
        }
        if (is_float($value) && ! is_finite($value)) {
            return null;
        }

        $text = is_bool($value) ? ($value ? 'да' : 'нет') : (string) $value;
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text, 'UTF-8') > $limit) {
            return rtrim(mb_substr($text, 0, max(1, $limit - 1), 'UTF-8')).'…';
        }

        return $text;
    }
}
