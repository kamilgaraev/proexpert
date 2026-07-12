<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Exceptions;

use RuntimeException;

class NormativeRerankingUnavailable extends RuntimeException
{
    public readonly bool $recoverable;

    public function __construct(string $message = 'Normative reranking is unavailable.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->recoverable = true;
    }
}
