<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue;

use RuntimeException;
use Throwable;

final class InterpretEstimateCommandFailure extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $retryDisposition,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function retryDisposition(): string
    {
        return $this->retryDisposition;
    }
}
