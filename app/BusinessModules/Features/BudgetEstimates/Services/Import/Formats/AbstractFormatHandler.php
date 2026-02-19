<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateTypeDetectionDTO;
use Illuminate\Support\Log;

abstract class AbstractFormatHandler implements ImportFormatHandlerInterface
{
    /**
     * Common logging for handlers
     */
    protected function logInfo(string $message, array $context = []): void
    {
        Log::info(sprintf('[%s] %s', $this->getSlug(), $message), $context);
    }

    protected function createDetectionDTO(bool $success = false): EstimateTypeDetectionDTO
    {
        $dto = new EstimateTypeDetectionDTO();
        $dto->confidence = $success ? 1.0 : 0.0;
        return $dto;
    }
}
