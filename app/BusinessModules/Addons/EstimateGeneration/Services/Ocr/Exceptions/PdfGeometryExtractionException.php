<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions;

use RuntimeException;
use Throwable;

final class PdfGeometryExtractionException extends RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message,
        public readonly array $context = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, previous: $previous);
    }
}
