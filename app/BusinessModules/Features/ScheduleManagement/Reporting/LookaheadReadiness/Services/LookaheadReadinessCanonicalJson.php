<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services;

use InvalidArgumentException;
use JsonException;

final class LookaheadReadinessCanonicalJson
{
    public static function sort(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                static fn (mixed $item): mixed => is_array($item) ? self::sort($item) : $item,
                $value,
            );
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sort($item);
            }
        }

        return $value;
    }

    public static function encode(array $value): string
    {
        try {
            return json_encode(
                self::sort($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('lookahead_readiness_payload_invalid', previous: $exception);
        }
    }

    public static function hash(array $value): string
    {
        return hash('sha256', self::encode($value));
    }
}
