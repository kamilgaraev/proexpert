<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evidence;

use InvalidArgumentException;

final readonly class EvidenceDomainCode
{
    public function __construct(public string $value)
    {
        $catalogReference = '(?:[1-9][0-9]*|[a-f0-9]{64}|[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})';
        if (preg_match('/^(?:material|work_type):'.$catalogReference.'$/D', $value) !== 1
            && preg_match('/^room_type:(?:bedroom|bathroom|kitchen|living|utility|corridor)$/D', $value) !== 1
            && preg_match('/^roof_type:(?:flat|pitched|gable|hip)$/D', $value) !== 1
            && preg_match('/^opening_type:(?:door|window|gate)$/D', $value) !== 1
            && preg_match('/^element_type:(?:wall|floor|roof|opening|room)$/D', $value) !== 1) {
            throw new InvalidArgumentException('Evidence domain code is invalid.');
        }
    }
}
