<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;

final class ContractTableGridValidator
{
    public function validate(array $table, string $path): void
    {
        $rows = [];
        foreach ($table['content'] as $index => $child) {
            if ($child['type'] === 'repeatRows') {
                foreach ($child['content'] as $position => $row) {
                    $rows[] = ['node' => $row, 'path' => $path.'.content.'.$index.'.content.'.$position, 'group' => $index, 'repeated' => true];
                }
            } else {
                $rows[] = ['node' => $child, 'path' => $path.'.content.'.$index, 'group' => null, 'repeated' => false];
            }
        }
        $grid = [];
        $columns = 0;
        $bodyStarted = false;
        foreach ($rows as $r => $row) {
            $header = $row['node']['attrs']['header'] ?? false;
            if (!is_bool($header) || ($header && ($bodyStarted || $row['repeated']))) {
                $this->invalid($row['path'], 'Строки заголовка должны идти подряд в начале таблицы, перед повторяемыми данными.');
            }
            $bodyStarted = $bodyStarted || !$header;
            $c = 0;
            foreach ($row['node']['content'] as $index => $cell) {
                while (isset($grid[$r][$c])) { $c++; }
                $width = $cell['attrs']['colspan'] ?? 1;
                $height = $cell['attrs']['rowspan'] ?? 1;
                $cellPath = $row['path'].'.content.'.$index;
                if ($r + $height > count($rows) || $c + $width > 1000 || ($r + $height) * ($c + $width) > 100000) {
                    $this->invalid($cellPath, 'Объединённая ячейка выходит за границы таблицы.');
                }
                for ($y = $r; $y < $r + $height; $y++) {
                    if ($rows[$y]['repeated'] !== $row['repeated'] || ($row['repeated'] && $rows[$y]['group'] !== $row['group'])
                        || ($rows[$y]['node']['attrs']['header'] ?? false) !== $header) {
                        $this->invalid($cellPath, 'Объединение не должно пересекать заголовок и данные или границу повторяемого блока.');
                    }
                    for ($x = $c; $x < $c + $width; $x++) {
                        if (isset($grid[$y][$x])) { $this->invalid($cellPath, 'Объединённые ячейки пересекаются. Разделите их и повторите объединение.'); }
                        $grid[$y][$x] = true;
                    }
                }
                $c += $width;
                $columns = max($columns, $c);
            }
            if (($grid[$r] ?? []) === []) { $this->invalid($row['path'], 'Строка таблицы не содержит ячеек. Добавьте ячейку или удалите строку.'); }
        }
        $widths = $table['attrs']['columnWidths'] ?? null;
        if ($widths !== null && (!is_array($widths) || !array_is_list($widths) || count($widths) !== $columns
            || array_filter($widths, static fn ($width): bool => (!is_int($width) && !is_float($width)) || !is_finite((float) $width) || $width <= 0 || $width > 100) !== []
            || abs(array_sum($widths) - 100) > .05)) {
            $this->invalid($path.'.attrs.columnWidths', 'Ширины должны быть положительными, соответствовать столбцам таблицы и в сумме составлять 100%.');
        }
    }

    private function invalid(string $path, string $reason): never
    {
        throw (new ContractBuilderException('contracts.builder_document_node_invalid', 422, ['location' => 'Таблица', 'reason' => $reason]))->atField($path);
    }
}
