<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Tenders\Exceptions;

use RuntimeException;

final class TenderWorkflowException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly array $blockers = [],
        private readonly int $statusCode = 409
    ) {
        parent::__construct($message, $statusCode);
    }

    public function blockers(): array
    {
        return $this->blockers;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
