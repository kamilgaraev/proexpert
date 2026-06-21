<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\PresaleEstimates\Exceptions;

use RuntimeException;

final class PresaleEstimateBudgetTransferException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 409,
        private readonly array $blockers = [],
        private readonly array $warnings = []
    ) {
        parent::__construct($message, $statusCode);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function blockers(): array
    {
        return $this->blockers;
    }

    public function warnings(): array
    {
        return $this->warnings;
    }
}
