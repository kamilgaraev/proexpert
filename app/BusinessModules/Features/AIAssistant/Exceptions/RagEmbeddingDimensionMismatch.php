<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class RagEmbeddingDimensionMismatch extends RuntimeException implements ShouldntReport
{
    public function __construct(public readonly int $expected, public readonly int $actual)
    {
        parent::__construct('rag_embedding_dimension_mismatch');
    }
}
