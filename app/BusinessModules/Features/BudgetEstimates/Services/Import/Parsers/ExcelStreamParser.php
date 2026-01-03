<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers;

use App\BusinessModules\Features\BudgetEstimates\Contracts\StreamParserInterface;
use OpenSpout\Reader\XLSX\Reader as XLSXReader;
// OpenSpout 4/5 dropped XLS support in main package? 
// No, it should be there but maybe namespace changed or requires extension?
// Let's check OpenSpout documentation or just use XLSX which is main format.
// If XLS is needed, we might need a different approach or verify namespace.
// Actually OpenSpout 4 removed XLS reader. It's now in a separate package or just not supported natively for streaming?
// Wait, openspout/openspout ^5.2 doesn't support XLS?
// Correct, OpenSpout 4 removed XLS support.
// We must fallback to PhpSpreadsheet for XLS or install separate package if available.
// Since we have PhpSpreadsheet, let's use it for XLS but warn about memory?
// Or just fail for now as we prioritized streaming for big files (usually XLSX).
// Let's remove XLS reader from here and let Factory handle it (Factory uses this parser).
// If Factory sees XLS, it might fail or we need a legacy parser.
// We have ExcelSimpleTableParser (using PhpSpreadsheet) which handles XLS.
// So we should only support XLSX and ODS here.

use OpenSpout\Reader\ODS\Reader as ODSReader;
use OpenSpout\Common\Entity\Row;
use Generator;
use RuntimeException;

class ExcelStreamParser implements StreamParserInterface
{
    public function parse(string $filePath): Generator
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("File not found: {$filePath}");
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        if ($extension === 'xls') {
             throw new RuntimeException("XLS format is not supported by StreamParser. Use standard parser.");
        }

        $reader = match ($extension) {
            'xlsx' => new XLSXReader(),
            'ods' => new ODSReader(),
            default => throw new RuntimeException("Unsupported file extension: {$extension}")
        };

        $reader->open($filePath);

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                /** @var Row $row */
                yield $row->toArray();
            }
        }

        $reader->close();
    }

    public function supports(string $extension): bool
    {
        return in_array(strtolower($extension), ['xlsx', 'ods']);
    }
}
