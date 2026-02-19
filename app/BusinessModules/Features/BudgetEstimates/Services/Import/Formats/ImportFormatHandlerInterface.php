<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateTypeDetectionDTO;
use App\Models\ImportSession;
use Illuminate\Support\Collection;

/**
 * Interface for format-specific import handlers (GrandSmeta, RIK, Generic, etc.)
 */
interface ImportFormatHandlerInterface
{
    /**
     * Unique slug for the format (e.g., 'grandsmeta-excel', 'generic-xlsx')
     */
    public function getSlug(): string;

    /**
     * Determine if this handler can process the given file/content.
     */
    public function canHandle(mixed $content, string $extension): EstimateTypeDetectionDTO;

    /**
     * Main entry point for parsing the file into a standardized preview/raw collection.
     */
    public function parse(ImportSession $session, mixed $content): Collection;

    /**
     * Handle the specific column mapping for this format.
     */
    public function applyMapping(ImportSession $session, array $mapping): void;
}
