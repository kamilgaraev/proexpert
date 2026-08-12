<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use RuntimeException;
use Throwable;

final class DocumentUnitProcessingException extends RuntimeException
{
    /** @param array<string, mixed> $resourceUsage */
    public function __construct(
        public readonly string $safeCode,
        ?Throwable $previous = null,
        public readonly array $resourceUsage = [],
    ) {
        parent::__construct($safeCode, 0, $previous);
    }
}
