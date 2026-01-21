<?php

namespace App\BusinessModules\Features\BudgetEstimates\Contracts;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use Generator;

interface StreamParserInterface
{
    /**
     * Reads the file line by line using a generator.
     * This ensures constant memory usage regardless of file size.
     *
     * @param string $filePath
     * @return Generator|EstimateImportDTO
     */
    public function parse(string $filePath): Generator|EstimateImportDTO;

    /**
     * Check if the parser supports the given file extension.
     *
     * @param string $extension
     * @return bool
     */
    public function supports(string $extension): bool;
}
