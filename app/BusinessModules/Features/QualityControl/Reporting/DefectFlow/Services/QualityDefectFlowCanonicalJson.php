<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Services;

use InvalidArgumentException;

final class QualityDefectFlowCanonicalJson
{
    public static function encode(array $value): string
    {
        return json_encode(
            self::sort($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    public static function hash(array $value): string
    {
        return hash('sha256', self::encode($value));
    }

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
            if (! is_string($key)) {
                throw new InvalidArgumentException('quality_defect_flow_canonical_key_invalid');
            }
            if (is_array($item)) {
                $value[$key] = self::sort($item);
            }
        }

        return $value;
    }
}
