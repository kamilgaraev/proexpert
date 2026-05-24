<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class RagEmbeddingUnavailableException extends RuntimeException implements ShouldntReport
{
}
