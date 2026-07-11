<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions;

use RuntimeException;

final class VisionContractException extends RuntimeException
{
    public function __construct(public readonly string $reason = 'invalid_vision_contract')
    {
        parent::__construct($reason);
    }
}
