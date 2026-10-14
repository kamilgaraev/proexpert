<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services;

/** JSONB may reorder object keys; canonical objects keep digest verification reproducible. */
final class TimekeepingSnapshotHash
{
    public static function calculate(array $snapshot): string
    {
        return hash('sha256', json_encode(self::canonical($snapshot), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = self::canonical($child);
            }
        }

        return $value;
    }
}
