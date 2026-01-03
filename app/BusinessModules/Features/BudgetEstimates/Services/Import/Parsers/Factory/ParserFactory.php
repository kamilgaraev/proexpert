<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\Factory;

use App\BusinessModules\Features\BudgetEstimates\Contracts\StreamParserInterface;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\ExcelStreamParser;
use RuntimeException;

class ParserFactory
{
    /**
     * @var StreamParserInterface[]
     */
    private array $parsers;

    public function __construct(ExcelStreamParser $excelParser)
    {
        // Register available parsers
        $this->parsers = [
            $excelParser,
            // Add other parsers here (e.g. CSV, XML)
        ];
    }

    public function getParser(string $filePath): StreamParserInterface
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);

        foreach ($this->parsers as $parser) {
            if ($parser->supports($extension)) {
                return $parser;
            }
        }

        throw new RuntimeException("No supported parser found for extension: {$extension}");
    }
}
