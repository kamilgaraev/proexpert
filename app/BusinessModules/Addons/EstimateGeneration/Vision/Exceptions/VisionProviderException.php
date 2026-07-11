<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions;

use RuntimeException;
use Throwable;

final class VisionProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?int $httpCode = null,
        public readonly bool $retryable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($reason, 0, $previous);
    }
}
