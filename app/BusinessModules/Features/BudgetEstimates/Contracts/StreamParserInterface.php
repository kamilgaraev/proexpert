<?php

namespace App\BusinessModules\Features\BudgetEstimates\Contracts;

use Generator;

interface StreamParserInterface
{
    /**
     * Reads the file line by line using a generator.
     * This ensures constant memory usage regardless of file size.
     *
     * @param string $filePath
     * @return Generator
     */
    public function parse(string $filePath): Generator;

    /**
     * Check if the parser supports the given file extension.
     *
     * @param string $extension
     * @return bool
     */
    public function supports(string $extension): bool;
}
