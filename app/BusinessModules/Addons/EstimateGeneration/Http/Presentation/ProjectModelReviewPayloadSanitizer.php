<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Presentation;

/** Serializes only the documented, user-facing projection fields. */
final class ProjectModelReviewPayloadSanitizer
{
    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function entity(array $payload): array
    {
        $kind = is_string($payload['kind'] ?? null) ? $payload['kind'] : null;
        if ($kind === 'table') {
            return $this->table($payload);
        }
        $keys = match ($kind) {
            'room' => ['kind', 'key', 'polygon'],
            'wall' => ['kind', 'key', 'start', 'end'],
            'opening' => ['kind', 'key', 'wall_key', 'type', 'width_m', 'height_m'],
            'dimension' => ['kind', 'key', 'value', 'unit'],
            'structural_element' => ['kind', 'key', 'type', 'length_m'],
            'quantity' => ['kind', 'key', 'value', 'unit'],
            default => [],
        };

        $result = $this->shape($payload, $keys);
        if ($kind === 'room' && array_key_exists('polygon', $result)) {
            $polygon = $this->polygon($result['polygon']);
            if ($polygon === null) {
                unset($result['polygon']);
            } else {
                $result['polygon'] = $polygon;
            }
        }

        return $result;
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    public function assertionValue(string $type, array $value): array
    {
        return $this->shape($value, match ($type) {
            'area', 'dimension', 'quantity' => ['value', 'unit'],
            'room_purpose' => ['value'],
            'opening' => ['type', 'width_m', 'height_m'],
            default => [],
        });
    }

    /** @param array<string,mixed> $payload @param list<string> $keys @return array<string,mixed> */
    private function shape(array $payload, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            $value = $this->value($payload[$key]);
            if ($value !== null) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function value(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_substr($value, 0, 1000);
        }
        if (is_bool($value) || $value === null || is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }
        if (! is_array($value)) {
            return null;
        }
        if (array_is_list($value)) {
            $result = [];
            foreach (array_slice($value, 0, 200) as $item) {
                $safe = $this->value($item);
                if ($safe !== null) {
                    $result[] = $safe;
                }
            }

            return $result;
        }

        return null;
    }

    private function polygon(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) < 3 || count($value) > 200) {
            return null;
        }

        $polygon = [];
        foreach ($value as $point) {
            if (! is_array($point) || ! array_is_list($point) || count($point) !== 2
                || ! is_numeric($point[0]) || ! is_numeric($point[1])) {
                return null;
            }

            $x = (float) $point[0];
            $y = (float) $point[1];
            if (! is_finite($x) || ! is_finite($y)) {
                return null;
            }

            $polygon[] = [$x, $y];
        }

        return $polygon;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function table(array $payload): array
    {
        $columns = $this->value($payload['columns'] ?? null);
        if (! is_array($columns) || ! array_is_list($columns)) {
            return $this->shape($payload, ['kind', 'key']);
        }
        $columns = array_values(array_filter($columns, static fn (mixed $column): bool => is_string($column) && $column !== ''));
        $rows = [];
        foreach (is_array($payload['rows'] ?? null) ? array_slice($payload['rows'], 0, 200) : [] as $row) {
            if (! is_array($row) || array_is_list($row)) {
                continue;
            }
            $safe = [];
            foreach ($columns as $column) {
                if (! array_key_exists($column, $row)) {
                    continue;
                }
                $value = $this->value($row[$column]);
                if ($value !== null && ! is_array($value)) {
                    $safe[$column] = $value;
                }
            }
            $rows[] = $safe;
        }

        return ['kind' => 'table', ...$this->shape($payload, ['key']), 'columns' => $columns, 'rows' => $rows];
    }
}
