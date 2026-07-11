<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

final class OperationalSnapshotRevision
{
    /** @param array<string, mixed> $sources */
    public static function fromSources(array $sources): string
    {
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map($normalize, $value);
        };
        $json = json_encode($normalize($sources), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return 'sha256:'.hash('sha256', $json);
    }
}
