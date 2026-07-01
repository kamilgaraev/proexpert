<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet;

use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SpreadsheetAiColumnMapper
{
    private const FIELDS = [
        'position_number',
        'code',
        'name',
        'unit',
        'quantity',
        'unit_price',
        'total_price',
    ];

    private const ASSIGNMENT_ORDER = [
        'position_number',
        'name',
        'unit',
        'quantity',
        'unit_price',
        'total_price',
        'code',
    ];

    private const FIELD_ALIASES = [
        'section_number' => 'position_number',
        'number' => 'position_number',
        'row_number' => 'position_number',
        'work_name' => 'name',
        'description' => 'name',
        'measure' => 'unit',
        'qty' => 'quantity',
        'volume' => 'quantity',
        'price' => 'unit_price',
        'amount' => 'total_price',
        'total' => 'total_price',
        'current_total_amount' => 'total_price',
    ];

    public function __construct(private ?LLMProviderInterface $llmProvider = null) {}

    public function improve(array $headers, array $sampleRows, array $currentMapping): array
    {
        $provider = $this->llmProvider;
        if ($provider === null || !$provider->isAvailable()) {
            return $this->result($currentMapping, false, 'provider_unavailable');
        }

        if ($headers === []) {
            return $this->result($currentMapping, false, 'headers_empty');
        }

        try {
            $response = $provider->chat($this->messages($headers, $sampleRows, $currentMapping), [
                'profile' => 'json',
                'temperature' => 0.05,
                'max_tokens' => 700,
            ]);

            $content = trim((string) ($response['content'] ?? ''));
            $payload = $this->decodeJson($content);
            if ($payload === null) {
                return $this->result($currentMapping, false, 'invalid_json');
            }

            $aiMapping = $this->normalizeMapping($payload['mapping'] ?? $payload, count($headers));
            $merged = $this->mergeMappings($currentMapping, $aiMapping);

            if (!$this->isUsable($merged)) {
                return $this->result($currentMapping, false, 'mapping_unusable');
            }

            return [
                'mapping' => $merged,
                'applied' => $aiMapping !== [],
                'reason' => 'ok',
                'confidence' => is_numeric($payload['confidence'] ?? null) ? (float) $payload['confidence'] : null,
                'model' => $provider->getModel(),
            ];
        } catch (Throwable $exception) {
            Log::warning('[EstimateImport] AI column mapping failed', [
                'error' => $exception->getMessage(),
            ]);

            return $this->result($currentMapping, false, 'failed');
        }
    }

    public function detectStructure(array $rows, array $currentDetection): array
    {
        $provider = $this->llmProvider;
        if ($provider === null || !$provider->isAvailable()) {
            return $this->structureResult($currentDetection, false, 'provider_unavailable');
        }

        $rowPayload = $this->rowsPayload($rows);
        if ($rowPayload === []) {
            return $this->structureResult($currentDetection, false, 'rows_empty');
        }

        try {
            $response = $provider->chat($this->structureMessages($rowPayload, $currentDetection), [
                'profile' => 'json',
                'temperature' => 0.03,
                'max_tokens' => 900,
            ]);

            $content = trim((string) ($response['content'] ?? ''));
            $payload = $this->decodeJson($content);
            if ($payload === null) {
                return $this->structureResult($currentDetection, false, 'invalid_json');
            }

            $headerRow = $this->normalizeHeaderRow($payload['header_row'] ?? ($payload['row'] ?? null), $rows);
            if ($headerRow === null) {
                return $this->structureResult($currentDetection, false, 'header_row_invalid');
            }

            $headers = $rows[$headerRow] ?? [];
            if (!is_array($headers) || $headers === []) {
                return $this->structureResult($currentDetection, false, 'headers_empty');
            }

            $mapping = $this->normalizeMapping($payload['mapping'] ?? [], count($headers));
            if (!$this->isUsable($mapping)) {
                return $this->structureResult($currentDetection, false, 'mapping_unusable');
            }

            $currentMapping = is_array($currentDetection['column_mapping'] ?? null)
                ? $currentDetection['column_mapping']
                : [];
            $confidence = is_numeric($payload['confidence'] ?? null) ? (float) $payload['confidence'] : null;
            if (
                !$this->shouldApplyStructureMapping($mapping, $currentMapping, $confidence)
            ) {
                return $this->structureResult($currentDetection, false, 'rules_are_stronger');
            }

            $detection = $currentDetection;
            $detection['header_row'] = $headerRow;
            $detection['raw_headers'] = $headers;
            $detection['column_mapping'] = $mapping;
            $detection['detected_columns'] = array_flip($mapping);
            $detection['score'] = max(
                (int) ($detection['score'] ?? 0),
                $this->mappingScore($mapping)
            );
            $detection['header_candidates'] = $this->prependHeaderCandidate(
                is_array($detection['header_candidates'] ?? null) ? $detection['header_candidates'] : [],
                $headerRow,
                $headers,
                $mapping,
                $confidence
            );

            return [
                'detection' => $detection,
                'applied' => true,
                'reason' => 'ok',
                'confidence' => $confidence,
                'model' => $provider->getModel(),
            ];
        } catch (Throwable $exception) {
            Log::warning('[EstimateImport] AI structure detection failed', [
                'error' => $exception->getMessage(),
            ]);

            return $this->structureResult($currentDetection, false, 'failed');
        }
    }

    private function messages(array $headers, array $sampleRows, array $currentMapping): array
    {
        $payload = [
            'headers' => $this->headersPayload($headers),
            'sample_rows' => array_slice(array_values($sampleRows), 0, 8),
            'current_mapping' => $currentMapping,
            'fields' => self::FIELDS,
        ];

        return [
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'Ты определяешь колонки сметы в Excel-таблице.',
                    'Верни только JSON без markdown.',
                    'Формат: {"mapping":{"position_number":"A","code":null,"name":"B","unit":"C","quantity":"D","unit_price":"E","total_price":"F"},"confidence":0.0}.',
                    'Доступные поля: position_number, code, name, unit, quantity, unit_price, total_price.',
                    'Расценка, цена за единицу, цена ед. и похожие заголовки относятся к unit_price, не к code.',
                    'ВСЕГО, сумма, итог и общая стоимость относятся к total_price.',
                    '№, N, п/п относятся к position_number.',
                    'code используй только для шифра, кода, норматива или обоснования расценки.',
                    'Если колонки нет, поставь null.',
                ]),
            ],
            [
                'role' => 'user',
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ];
    }

    private function structureMessages(array $rows, array $currentDetection): array
    {
        $payload = [
            'rows' => $rows,
            'current_detection' => [
                'header_row' => $currentDetection['header_row'] ?? null,
                'mapping' => $currentDetection['column_mapping'] ?? [],
                'score' => $currentDetection['score'] ?? 0,
            ],
            'fields' => self::FIELDS,
        ];

        return [
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'You detect the header row and columns in construction estimate spreadsheets.',
                    'Return only JSON without markdown.',
                    'Format: {"header_row":3,"mapping":{"position_number":"A","code":null,"name":"B","unit":"C","quantity":"D","unit_price":"E","total_price":"F"},"confidence":0.0}.',
                    'Allowed fields: position_number, code, name, unit, quantity, unit_price, total_price.',
                    'Pick the row that contains column names, not a title, section row, total row, or data row.',
                    'Map quantity/volume/count to quantity, unit of measure to unit, rate/unit price to unit_price, and line total/amount/sum to total_price.',
                    'Use code only for estimate code, rate code, cipher, normative code, or justification. Do not map unit price to code.',
                    'If a column does not exist, use null.',
                ]),
            ],
            [
                'role' => 'user',
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ];
    }

    private function headersPayload(array $headers): array
    {
        $payload = [];

        foreach ($headers as $index => $header) {
            $payload[] = [
                'column' => $this->columnName((int) $index),
                'header' => $header,
            ];
        }

        return $payload;
    }

    private function rowsPayload(array $rows): array
    {
        $payload = [];

        foreach ($rows as $rowNumber => $row) {
            if (!is_array($row)) {
                continue;
            }

            $cells = [];
            foreach (array_slice($row, 0, 24, true) as $index => $value) {
                $value = $this->stringify($value);
                if ($value === '') {
                    continue;
                }

                $cells[] = [
                    'column' => $this->columnName((int) $index),
                    'value' => $value,
                ];
            }

            if (count($cells) < 2) {
                continue;
            }

            $payload[] = [
                'row_number' => (int) $rowNumber,
                'cells' => $cells,
            ];

            if (count($payload) >= 25) {
                break;
            }
        }

        return $payload;
    }

    private function decodeJson(string $content): ?array
    {
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/su', $content, $match) !== 1) {
            return null;
        }

        $decoded = json_decode($match[0], true);

        return is_array($decoded) ? $decoded : null;
    }

    private function normalizeMapping(mixed $mapping, int $columnsCount): array
    {
        if (!is_array($mapping)) {
            return [];
        }

        $allowedColumns = [];
        for ($index = 0; $index < $columnsCount; $index++) {
            $allowedColumns[$this->columnName($index)] = true;
        }

        $fieldColumns = [];
        foreach ($mapping as $field => $column) {
            if (!is_string($field)) {
                continue;
            }

            $field = $this->canonicalField($field);
            if ($field === null || isset($fieldColumns[$field])) {
                continue;
            }

            $column = $this->normalizeColumnReference($column, $allowedColumns, $columnsCount);
            if ($column === null) {
                continue;
            }

            $fieldColumns[$field] = $column;
        }

        $normalized = [];
        $usedColumns = [];
        foreach (self::ASSIGNMENT_ORDER as $field) {
            $column = $fieldColumns[$field] ?? null;
            if ($column === null || isset($usedColumns[$column])) {
                continue;
            }

            $normalized[$field] = $column;
            $usedColumns[$column] = true;
        }

        return $normalized;
    }

    private function mergeMappings(array $currentMapping, array $aiMapping): array
    {
        if ($aiMapping === []) {
            return $currentMapping;
        }

        $merged = $aiMapping;
        $usedColumns = array_flip($merged);

        foreach ($currentMapping as $field => $column) {
            if (isset($merged[$field]) || !is_string($field) || !is_string($column) || isset($usedColumns[$column])) {
                continue;
            }

            if (!in_array($field, self::FIELDS, true)) {
                continue;
            }

            $merged[$field] = $column;
            $usedColumns[$column] = true;
        }

        return $merged;
    }

    private function isUsable(array $mapping): bool
    {
        return isset($mapping['name'])
            && (isset($mapping['quantity']) || isset($mapping['unit_price']) || isset($mapping['total_price']));
    }

    private function shouldApplyStructureMapping(array $aiMapping, array $currentMapping, ?float $confidence): bool
    {
        if (!$this->isUsable($currentMapping)) {
            return true;
        }

        $aiScore = $this->mappingScore($aiMapping);
        $currentScore = $this->mappingScore($currentMapping);

        if ($aiScore >= $currentScore + 20) {
            return true;
        }

        return $confidence !== null && $confidence >= 0.75 && $aiScore >= $currentScore;
    }

    private function normalizeHeaderRow(mixed $headerRow, array $rows): ?int
    {
        if (!is_int($headerRow) && !(is_string($headerRow) && ctype_digit(trim($headerRow)))) {
            return null;
        }

        $rowNumber = (int) $headerRow;

        return isset($rows[$rowNumber]) ? $rowNumber : null;
    }

    private function prependHeaderCandidate(array $candidates, int $headerRow, array $headers, array $mapping, ?float $confidence): array
    {
        array_unshift($candidates, [
            'row_index' => $headerRow,
            'score' => $this->mappingScore($mapping),
            'confidence' => $confidence,
            'headers' => $headers,
            'mapping' => $mapping,
            'detectors' => ['ai'],
        ]);

        return $candidates;
    }

    private function mappingScore(array $mapping): int
    {
        $weights = [
            'name' => 35,
            'quantity' => 25,
            'unit_price' => 25,
            'total_price' => 25,
            'unit' => 15,
            'position_number' => 10,
            'code' => 8,
        ];

        $score = 0;
        foreach ($weights as $field => $weight) {
            if (isset($mapping[$field])) {
                $score += $weight;
            }
        }

        return $score;
    }

    private function canonicalField(string $field): ?string
    {
        $field = trim($field);
        $field = self::FIELD_ALIASES[$field] ?? $field;

        return in_array($field, self::FIELDS, true) ? $field : null;
    }

    private function normalizeColumnReference(mixed $column, array $allowedColumns, int $columnsCount): ?string
    {
        if (is_int($column) || (is_string($column) && ctype_digit(trim($column)))) {
            $index = (int) $column;

            return $index >= 1 && $index <= $columnsCount ? $this->columnName($index - 1) : null;
        }

        if (!is_string($column)) {
            return null;
        }

        $column = strtoupper(trim($column));
        if (preg_match('/^[A-Z]{1,3}$/', $column) !== 1) {
            if (preg_match('/\b([A-Z]{1,3})\b/', $column, $match) !== 1) {
                return null;
            }

            $column = $match[1];
        }

        return isset($allowedColumns[$column]) ? $column : null;
    }

    private function result(array $mapping, bool $applied, string $reason): array
    {
        return [
            'mapping' => $mapping,
            'applied' => $applied,
            'reason' => $reason,
            'confidence' => null,
            'model' => null,
        ];
    }

    private function structureResult(array $detection, bool $applied, string $reason): array
    {
        return [
            'detection' => $detection,
            'applied' => $applied,
            'reason' => $reason,
            'confidence' => null,
            'model' => null,
        ];
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim(str_replace(["\r", "\n", "\xc2\xa0"], ' ', (string) $value));
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
