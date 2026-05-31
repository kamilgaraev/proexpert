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

        $normalized = [];
        $usedColumns = [];
        foreach (self::FIELDS as $field) {
            $column = $mapping[$field] ?? null;
            if (!is_string($column) && !is_int($column)) {
                continue;
            }

            $column = strtoupper(trim((string) $column));
            if (!preg_match('/^[A-Z]{1,3}$/', $column) || !isset($allowedColumns[$column]) || isset($usedColumns[$column])) {
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
