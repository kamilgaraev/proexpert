<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO;

use InvalidArgumentException;

final class EvidenceBounds
{
    public const MAX_ITEMS = 32;

    public const MAX_BYTES = 128;

    public static function assert(array $evidence): void
    {
        if (! array_is_list($evidence) || count($evidence) > self::MAX_ITEMS || count(array_unique($evidence)) !== count($evidence)) {
            throw new InvalidArgumentException('Source evidence must be a bounded unique list.');
        }
        foreach ($evidence as $reference) {
            if (! is_string($reference) || $reference === '' || strlen($reference) > self::MAX_BYTES) {
                throw new InvalidArgumentException('Source evidence reference is invalid.');
            }
        }
    }
}
