<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Catalog;

use InvalidArgumentException;
use LogicException;

final class ReportCodeSetComparator
{
    public function validate(array $codes, string $subject): array
    {
        $seen = [];
        foreach ($codes as $code) {
            if (! is_string($code)
                || preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $code) !== 1) {
                throw new InvalidArgumentException("{$subject}_invalid");
            }
            if (array_key_exists($code, $seen)) {
                throw new LogicException("{$subject}_duplicate");
            }
            $seen[$code] = true;
        }

        return array_values($codes);
    }

    public function equal(array $left, array $right): bool
    {
        $leftSet = $left;
        $rightSet = $right;
        sort($leftSet, SORT_STRING);
        sort($rightSet, SORT_STRING);

        return $leftSet === $rightSet;
    }
}
