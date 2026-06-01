<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet;

final class SpreadsheetHeaderDetector
{
    private const FIELDS = [
        'position_number' => [
            '№ пп' => 34,
            '№ п/п' => 34,
            'n пп' => 28,
            'п/п' => 26,
            'номер позиции' => 32,
            '№ позиции' => 30,
            'позиция №' => 24,
            'номер' => 24,
            '№' => 20,
            'no' => 16,
            'number' => 16,
        ],
        'name' => [
            'наименование работ' => 36,
            'наименование затрат' => 34,
            'наименование' => 32,
            'позиция' => 30,
            'описание' => 28,
            'работ' => 24,
            'затрат' => 22,
            'name' => 20,
            'description' => 20,
        ],
        'unit' => [
            'ед.изм' => 34,
            'ед изм' => 32,
            'единица измерения' => 32,
            'единица' => 28,
            'ед.' => 24,
            'изм' => 18,
            'unit' => 18,
        ],
        'quantity' => [
            'кол-во' => 34,
            'к-во' => 32,
            'количество' => 30,
            'объем' => 28,
            'объём' => 28,
            'qty' => 20,
            'quantity' => 20,
        ],
        'unit_price' => [
            'расценка' => 40,
            'цена за ед' => 36,
            'цена ед' => 34,
            'стоимость единицы' => 34,
            'стоимость за ед' => 34,
            'ед. руб' => 28,
            'руб за ед' => 28,
            'цена' => 26,
            'price' => 20,
        ],
        'total_price' => [
            'всего' => 40,
            'всего руб' => 38,
            'общая стоимость' => 34,
            'стоимость всего' => 34,
            'стоимость руб' => 32,
            'итого' => 28,
            'сумма' => 26,
            'стоимость' => 22,
            'total' => 20,
            'amount' => 20,
        ],
        'code' => [
            'обоснование' => 34,
            'шифр' => 32,
            'код' => 30,
            'норматив' => 28,
            'фер' => 18,
            'тер' => 18,
            'гэсн' => 18,
            'code' => 18,
        ],
    ];

    private const FIELD_PRIORITY = [
        'position_number' => 70,
        'name' => 65,
        'unit' => 60,
        'quantity' => 58,
        'unit_price' => 56,
        'total_price' => 54,
        'code' => 40,
    ];

    public function detect(array $rows): array
    {
        $best = [
            'header_row' => null,
            'score' => 0,
            'raw_headers' => [],
            'column_mapping' => [],
            'detected_columns' => [],
            'header_candidates' => [],
        ];

        foreach ($rows as $rowNumber => $row) {
            $mapping = $this->mapHeaders($row);
            $score = $this->scoreMapping($mapping, $row);

            if ($score > 0) {
                $best['header_candidates'][] = [
                    'row_index' => $rowNumber,
                    'score' => $score,
                    'headers' => $row,
                    'mapping' => $mapping,
                ];
            }

            if ($score > $best['score']) {
                $best['header_row'] = $rowNumber;
                $best['score'] = $score;
                $best['raw_headers'] = $row;
                $best['column_mapping'] = $mapping;
                $best['detected_columns'] = array_flip($mapping);
            }
        }

        usort(
            $best['header_candidates'],
            static fn (array $left, array $right): int => $right['score'] <=> $left['score']
        );

        return $best;
    }

    public function detectHeaderRow(array $rows, int $headerRow): array
    {
        $rawHeaders = $rows[$headerRow] ?? [];
        $mapping = $this->mapHeaders($rawHeaders);
        $score = $this->scoreMapping($mapping, $rawHeaders);

        return [
            'header_row' => $headerRow,
            'score' => $score,
            'raw_headers' => $rawHeaders,
            'column_mapping' => $mapping,
            'detected_columns' => array_flip($mapping),
            'header_candidates' => $mapping !== [] ? [[
                'row_index' => $headerRow,
                'score' => $score,
                'headers' => $rawHeaders,
                'mapping' => $mapping,
            ]] : [],
        ];
    }

    public function mapHeaders(array $headers): array
    {
        $candidates = [];
        $mapping = [];

        foreach ($headers as $index => $header) {
            $normalized = $this->normalize((string) $header);
            if ($normalized === '') {
                continue;
            }

            $best = $this->bestFieldForHeader($normalized);
            if ($best === null) {
                continue;
            }

            $candidates[] = [
                'field' => $best['field'],
                'column' => $this->columnName($index),
                'score' => $best['score'],
                'index' => $index,
            ];
        }

        usort($candidates, static function (array $left, array $right): int {
            $scoreComparison = $right['score'] <=> $left['score'];
            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            $priorityComparison = (self::FIELD_PRIORITY[$right['field']] ?? 0)
                <=> (self::FIELD_PRIORITY[$left['field']] ?? 0);
            if ($priorityComparison !== 0) {
                return $priorityComparison;
            }

            return $left['index'] <=> $right['index'];
        });

        $usedColumns = [];
        foreach ($candidates as $candidate) {
            $field = (string) $candidate['field'];
            $column = (string) $candidate['column'];

            if (isset($mapping[$field]) || isset($usedColumns[$column])) {
                continue;
            }

            $mapping[$field] = $column;
            $usedColumns[$column] = true;
        }

        return $mapping;
    }

    private function scoreMapping(array $mapping, array $row): int
    {
        $score = count($mapping) * 10;

        foreach (['name', 'quantity', 'unit_price', 'total_price'] as $field) {
            if (isset($mapping[$field])) {
                $score += 10;
            }
        }

        if (count(array_filter($row, static fn (mixed $value): bool => trim((string) $value) !== '')) < 3) {
            $score -= 20;
        }

        return max(0, $score);
    }

    private function bestFieldForHeader(string $normalized): ?array
    {
        $bestField = null;
        $bestScore = 0;

        foreach (self::FIELDS as $field => $keywords) {
            foreach ($keywords as $keyword => $score) {
                $keyword = $this->normalize((string) $keyword);
                if ($keyword === '' || !str_contains($normalized, $keyword)) {
                    continue;
                }

                $candidateScore = (int) $score;
                if ($normalized === $keyword) {
                    $candidateScore += 8;
                }

                if (
                    $candidateScore > $bestScore
                    || (
                        $candidateScore === $bestScore
                        && (self::FIELD_PRIORITY[$field] ?? 0) > (self::FIELD_PRIORITY[$bestField] ?? 0)
                    )
                ) {
                    $bestField = (string) $field;
                    $bestScore = $candidateScore;
                }
            }
        }

        if ($bestField === null) {
            return null;
        }

        return [
            'field' => $bestField,
            'score' => $bestScore,
        ];
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower($value);
        $value = str_replace(["\r", "\n", "\t", "\xc2\xa0"], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = preg_replace('/[^\p{L}\p{N}\s.№\/-]+/u', '', $value) ?? $value;

        return trim($value);
    }

    private function columnName(int $zeroBasedIndex): string
    {
        $index = $zeroBasedIndex + 1;
        $name = '';

        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)) . $name;
            $index = intdiv($index, 26);
        }

        return $name;
    }
}
