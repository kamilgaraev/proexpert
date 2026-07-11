<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

final class BenchmarkExpectedContract
{
    private const DATA_KEYS = [
        'sheet_type', 'room_cells', 'wall_cells', 'opening_ids', 'areas', 'quantities',
        'work_ids', 'normative_rankings', 'costs', 'applicable_item_ids', 'evidence_ids_by_item',
    ];

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public static function expected(array $payload, string $expectedVersion): array
    {
        self::exactKeys($payload, ['schema_version', 'expected_model_schema_version', 'expected']);
        if (($payload['schema_version'] ?? null) !== 1 || ($payload['expected_model_schema_version'] ?? null) !== $expectedVersion
            || ! is_array($payload['expected'] ?? null)) {
            throw new BenchmarkContractException('expected_schema_invalid');
        }
        self::data($payload['expected']);

        return $payload['expected'];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public static function prediction(array $payload): array
    {
        self::exactKeys($payload, [...self::DATA_KEYS, 'model_schema_version']);
        self::token($payload['model_schema_version'] ?? null, 'prediction_model_schema_invalid');
        self::data($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private static function data(array $payload): void
    {
        $data = $payload;
        unset($data['model_schema_version']);
        self::exactKeys($data, self::DATA_KEYS);
        self::token($data['sheet_type'] ?? null, 'sheet_type_invalid');
        $rooms = self::tokenList($data['room_cells'] ?? null, 'room_cells_invalid', 10_000);
        self::tokenList($data['wall_cells'] ?? null, 'wall_cells_invalid', 50_000);
        self::tokenList($data['opening_ids'] ?? null, 'opening_ids_invalid', 10_000);
        self::decimalMap($data['areas'] ?? null, 'areas_invalid', $rooms);
        self::decimalMap($data['quantities'] ?? null, 'quantities_invalid');
        $workIds = self::tokenList($data['work_ids'] ?? null, 'work_ids_invalid', 10_000);
        self::rankings($data['normative_rankings'] ?? null, $workIds);
        self::decimalMap($data['costs'] ?? null, 'costs_invalid', $workIds);
        $applicable = self::tokenList($data['applicable_item_ids'] ?? null, 'applicable_items_invalid', 10_000);
        self::evidence($data['evidence_ids_by_item'] ?? null, $applicable);
    }

    /** @param mixed $value @return list<string> */
    private static function tokenList(mixed $value, string $code, int $limit): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $limit) {
            throw new BenchmarkContractException($code);
        }
        $result = [];
        foreach ($value as $item) {
            $result[] = self::token($item, $code);
        }
        if (count($result) !== count(array_unique($result))) {
            throw new BenchmarkContractException($code);
        }

        return $result;
    }

    /** @param mixed $value @param list<string>|null $allowedKeys */
    private static function decimalMap(mixed $value, string $code, ?array $allowedKeys = null): void
    {
        if (! is_array($value) || array_is_list($value) && $value !== [] || count($value) > 10_000) {
            throw new BenchmarkContractException($code);
        }
        foreach ($value as $key => $amount) {
            self::token($key, $code);
            if ($allowedKeys !== null && ! in_array($key, $allowedKeys, true)) {
                throw new BenchmarkContractException($code);
            }
            if (! is_string($amount) || ! preg_match('/^(0|[1-9][0-9]{0,12})(?:\.[0-9]{1,9})?$/', $amount)) {
                throw new BenchmarkContractException($code);
            }
        }
    }

    /** @param mixed $value @param list<string> $workIds */
    private static function rankings(mixed $value, array $workIds): void
    {
        if (! is_array($value) || array_is_list($value) && $value !== [] || count($value) > count($workIds)) {
            throw new BenchmarkContractException('normative_rankings_invalid');
        }
        foreach ($value as $workId => $ranking) {
            if (! in_array($workId, $workIds, true)) {
                throw new BenchmarkContractException('normative_rankings_invalid');
            }
            self::tokenList($ranking, 'normative_rankings_invalid', 100);
        }
    }

    /** @param mixed $value @param list<string> $applicable */
    private static function evidence(mixed $value, array $applicable): void
    {
        if (! is_array($value) || array_is_list($value) && $value !== [] || count($value) > count($applicable)) {
            throw new BenchmarkContractException('evidence_invalid');
        }
        foreach ($value as $itemId => $evidenceIds) {
            if (! in_array($itemId, $applicable, true)) {
                throw new BenchmarkContractException('evidence_invalid');
            }
            self::tokenList($evidenceIds, 'evidence_invalid', 100);
        }
    }

    private static function token(mixed $value, string $code): string
    {
        if (! is_string($value) || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/', $value)) {
            throw new BenchmarkContractException($code);
        }

        return $value;
    }

    /** @param array<string, mixed> $payload @param list<string> $expected */
    private static function exactKeys(array $payload, array $expected): void
    {
        $actual = array_keys($payload);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new BenchmarkContractException('contract_keys_invalid');
        }
    }
}
