<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Exceptions;

use RuntimeException;

class FgiscsDownloadUnavailableException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly ?string $responseBody = null,
    ) {
        parent::__construct($message);
    }
}
