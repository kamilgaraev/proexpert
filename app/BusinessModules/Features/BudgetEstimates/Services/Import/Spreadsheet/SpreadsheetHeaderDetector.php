<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet;

final class SpreadsheetHeaderDetector
{
    private const FIELDS = [
        'position_number' => ['n пп', '№ пп', 'номер', 'позиция', 'п/п', 'no'],
        'code' => ['шифр', 'код', 'обоснование', 'норматив', 'расценка', 'code'],
        'name' => ['наименование', 'работ', 'затрат', 'описание', 'name', 'description'],
        'unit' => ['единица', 'ед.', 'ед изм', 'изм', 'unit'],
        'quantity' => ['количество', 'кол-во', 'объем', 'объём', 'qty', 'quantity'],
        'unit_price' => ['цена', 'стоимость единицы', 'единицы', 'price'],
        'total_price' => ['сумма', 'общая стоимость', 'итого', 'total', 'amount'],
    ];

    /**
     * @param array<int, array<int, mixed>> $rows
     * @return array<string, mixed>
     */
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

    /**
     * @param array<int, mixed> $headers
     * @return array<string, string>
     */
    public function mapHeaders(array $headers): array
    {
        $mapping = [];

        foreach ($headers as $index => $header) {
            $normalized = $this->normalize((string) $header);
            if ($normalized === '') {
                continue;
            }

            foreach (self::FIELDS as $field => $keywords) {
                if (isset($mapping[$field])) {
                    continue;
                }

                foreach ($keywords as $keyword) {
                    if (str_contains($normalized, $this->normalize($keyword))) {
                        $mapping[$field] = $this->columnName($index);
                        break;
                    }
                }
            }
        }

        return $mapping;
    }

    /**
     * @param array<string, string> $mapping
     * @param array<int, mixed> $row
     */
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
