<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions;

use RuntimeException;

final class GeometryExtractionException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly bool $retryable = false
    ) {
        parent::__construct($reason);
    }
}
