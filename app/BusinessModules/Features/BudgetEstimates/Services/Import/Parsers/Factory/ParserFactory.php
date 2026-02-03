<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\Factory;

use App\BusinessModules\Features\BudgetEstimates\Contracts\StreamParserInterface;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\ExcelStreamParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\GrandSmetaXMLParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\UniversalXmlParser;
use RuntimeException;

class ParserFactory
{
    /**
     * @var StreamParserInterface[]
     */
    private array $parsers;

    public function __construct(
        ExcelStreamParser $excelParser,
        GrandSmetaXMLParser $grandSmetaParser
    ) {
        // Register available parsers
        $this->parsers = [
            $excelParser,
            $grandSmetaParser, // Priority: specialized parser first
            new UniversalXmlParser(), // Fallback: universal parser
        ];
    }

    public function getParser(string $filePath): StreamParserInterface
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        
        // Find all parsers that support this extension
        $candidates = [];
        foreach ($this->parsers as $parser) {
            if ($parser->supports($extension)) {
                $candidates[] = $parser;
            }
        }

        if (empty($candidates)) {
            throw new RuntimeException("No supported parser found for extension: {$extension}");
        }

        // Try to find the best match using validation
        foreach ($candidates as $parser) {
            // If parser has validation logic, check content
            if (method_exists($parser, 'validateFile')) {
                if ($parser->validateFile($filePath)) {
                    return $parser;
                }
            } else {
                // If parser doesn't have validation, accept it (optimistic match)
                // But better to prefer validated ones first.
                // In our current setup, all have validateFile.
                return $parser;
            }
        }
        
        // Fallback: if no validation passed (unlikely for valid files), return the first one
        // or throw exception? 
        // For UniversalXmlParser, validateFile returns true for almost any XML.
        // So we should have returned already.
        
        return $candidates[0];
    }
}
