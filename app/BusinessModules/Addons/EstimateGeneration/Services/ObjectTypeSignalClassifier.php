<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

final class ObjectTypeSignalClassifier
{
    public static function isResidential(string $text): bool
    {
        return preg_match('/(?:^|[^\p{L}\p{N}])(?:ижс|жил\p{L}*|дом|house|residential)(?=$|[^\p{L}\p{N}])/u', mb_strtolower($text)) === 1;
    }
}
