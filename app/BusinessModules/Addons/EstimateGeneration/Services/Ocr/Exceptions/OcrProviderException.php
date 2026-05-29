<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions;

use RuntimeException;
use Throwable;

class OcrProviderException extends RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $messageKey,
        public readonly ?int $statusCode = null,
        public readonly ?string $providerCode = null,
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($messageKey, $statusCode ?? 0, $previous);
    }
}
