<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use RuntimeException;

final class RerankWireException extends RuntimeException
{
    public function __construct(public readonly string $attemptStatus, public readonly ?int $httpCode = null)
    {
        parent::__construct('reranker_wire_failed');
    }
}
