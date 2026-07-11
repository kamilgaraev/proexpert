<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use RuntimeException;
use Throwable;

class TypedFailureException extends RuntimeException
{
    /** @param array<string, mixed> $safeContext */
    public function __construct(
        public readonly FailureCategory $category,
        public readonly string $safeCode,
        public readonly array $safeContext = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($safeCode, previous: $previous);
    }
}
