<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use Brick\Math\BigDecimal;

final class WorkVolumeStatementDiff
{
    private const FIELDS = ['name', 'unit_code', 'quantity', 'place', 'measurement_formula', 'basis_revision', 'estimate_item_id', 'metadata'];

    public function compare(array $before, array $after): array
    {
        $old = array_column($before, null, 'line_key');
        $new = array_column($after, null, 'line_key');
        $keys = array_unique([...array_keys($old), ...array_keys($new)]);
        sort($keys);
        $changes = [];
        foreach ($keys as $key) {
            $previous = $old[$key] ?? null;
            $current = $new[$key] ?? null;
            $fields = [];
            if ($previous !== null && $current !== null) {
                foreach (self::FIELDS as $field) {
                    if (! $this->equal($field, $previous[$field] ?? null, $current[$field] ?? null)) {
                        $fields[] = $field;
                    }
                }
                if ($fields === []) {
                    continue;
                }
            }
            $changes[] = [
                'line_key' => $key,
                'change' => $previous === null ? 'added' : ($current === null ? 'removed' : 'changed'),
                'changed_fields' => $fields,
                'before' => $previous,
                'after' => $current,
            ];
        }
        return $changes;
    }

    private function equal(string $field, mixed $left, mixed $right): bool
    {
        if ($field === 'quantity' && $left !== null && $right !== null) {
            return BigDecimal::of((string) $left)->isEqualTo(BigDecimal::of((string) $right));
        }
        return $this->canonical($left) === $this->canonical($right);
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        return array_map($this->canonical(...), $value);
    }
}
