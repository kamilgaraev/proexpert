<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\GrandSmeta;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateTypeDetectionDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\AbstractFormatHandler;
use App\Models\ImportSession;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class GrandSmetaHandler extends AbstractFormatHandler
{
    public function getSlug(): string
    {
        return 'grandsmeta';
    }

    public function canHandle(mixed $content, string $extension): EstimateTypeDetectionDTO
    {
        $dto = $this->createDetectionDTO();

        if ($content instanceof Spreadsheet) {
            $sheet = $content->getActiveSheet();
            
            // Look for "ГРАНД-Смета" or typical order 421 header in first 50 rows
            $searchText = 'ГРАНД-Смета';
            $found = false;
            
            foreach ($sheet->getRowIterator(1, 15) as $row) {
                foreach ($row->getCellIterator() as $cell) {
                    if (str_contains((string)$cell->getValue(), $searchText)) {
                        $found = true;
                        break 2;
                    }
                }
            }

            if ($found) {
                $dto->confidence = 1.0;
                $dto->detectedType = 'grandsmeta';
            }
        }

        return $dto;
    }

    public function parse(ImportSession $session, mixed $content): Collection
    {
        if (!$content instanceof Spreadsheet) {
            return collect();
        }

        $sheet = $content->getActiveSheet();
        $mapping = $session->options['structure']['column_mapping'] ?? $this->getDefaultMapping();
        $headerRow = $session->options['structure']['header_row'] ?? 44; // Default starting for LSR 421

        $processor = new StatefulGrandSmetaProcessor();

        foreach ($sheet->getRowIterator($headerRow + 1) as $row) {
            $rowData = [];
            foreach ($row->getCellIterator() as $cell) {
                $rowData[$cell->getColumn()] = $cell->getCalculatedValue();
            }

            // Check if row is empty
            if (empty(array_filter($rowData))) continue;

            $processor->processRow($rowData, $mapping, $row->getRowIndex());
        }

        $result = $processor->getResult();
        
        return collect([
            'items' => $result['items'],
            'sections' => $result['sections']
        ]);
    }

    private function getDefaultMapping(): array
    {
        return [
            'code' => 'B',
            'name' => 'C',
            'unit' => 'D',
            'quantity' => 'G',
            'unit_price' => 'H', // Assuming total current price as "unit price" for prohelper if resources are merged
            'total_price' => 'J'
        ];
    }

    public function applyMapping(ImportSession $session, array $mapping): void
    {
        // GrandSmeta usually has a fixed or very predictable mapping
        $options = $session->options ?? [];
        $options['column_mapping'] = $mapping;
        $session->update(['options' => $options]);
    }
}
