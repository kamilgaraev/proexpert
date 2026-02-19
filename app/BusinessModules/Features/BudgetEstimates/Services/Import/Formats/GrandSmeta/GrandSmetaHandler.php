<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\GrandSmeta;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateTypeDetectionDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\AbstractFormatHandler;
use App\Models\ImportSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
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
        
        // 1. Try to find the numbering row and dynamic mapping
        $detection = $this->findHeaderAndMapping($sheet);
        
        $mapping = $session->options['column_mapping'] ?? $detection['mapping'];
        $headerRow = $session->options['structure']['header_row'] ?? $detection['header_row'];

        Log::info('[GrandSmetaHandler] Using mapping', [
            'mapping' => $mapping,
            'header_row' => $headerRow,
            'source' => isset($session->options['column_mapping']) ? 'session' : 'detected'
        ]);

        $processor = new StatefulGrandSmetaProcessor();

        foreach ($sheet->getRowIterator($headerRow + 1) as $row) {
            $rowData = [];
            foreach ($row->getCellIterator() as $cell) {
                $rowData[$cell->getColumn()] = $cell->getCalculatedValue();
            }

            if (empty(array_filter($rowData))) continue;

            $processor->processRow($rowData, $mapping, $row->getRowIndex());
        }

        $result = $processor->getResult();
        
        return collect([
            'items' => array_map(fn($item) => $item->toArray(), $result['items']),
            'sections' => array_map(fn($section) => $section->toArray(), $result['sections'])
        ]);
    }

    public function findHeaderAndMapping($sheet): array
    {
        $maxScanRows = 60;
        $mapping = $this->getDefaultMapping();
        $headerRow = 44; // Fallback

        foreach ($sheet->getRowIterator(1, $maxScanRows) as $row) {
            $rowData = [];
            $foundMarkers = 0;
            $tempMapping = [];
            
            foreach ($row->getCellIterator() as $cell) {
                $val = trim((string)$cell->getValue());
                $col = $cell->getColumn();
                
                if ($val === '1') { $tempMapping['position_number'] = $col; $foundMarkers++; }
                if ($val === '2') { $tempMapping['code'] = $col; $foundMarkers++; }
                if ($val === '3') { $tempMapping['name'] = $col; $foundMarkers++; }
                if ($val === '4') { $tempMapping['unit'] = $col; $foundMarkers++; }
                if ($val === '5' || $val === '7') { $tempMapping['quantity'] = $col; $foundMarkers++; }
                if ($val === '8' || $val === '11') { $tempMapping['unit_price'] = $col; $foundMarkers++; }
                if ($val === '10' || $val === '12' || $val === '13' || $val === '14') { $tempMapping['total_price'] = $col; $foundMarkers++; }
            }

            // If we found at least 4 key markers (including 1, 2, 3), this is our numbering row
            if ($foundMarkers >= 4) {
                return [
                    'mapping' => array_merge($this->getDefaultMapping(), $tempMapping),
                    'header_row' => $row->getRowIndex()
                ];
            }
        }

        return ['mapping' => $mapping, 'header_row' => $headerRow];
    }

    private function getDefaultMapping(): array
    {
        return [
            'position_number' => 'A',
            'code' => 'B',
            'name' => 'C',
            'unit' => 'D',
            'quantity' => 'G',
            'unit_price' => 'H',
            'total_price' => 'J'
        ];
    }

    public function applyMapping(ImportSession $session, array $mapping): void
    {
        $options = $session->options ?? [];
        $options['column_mapping'] = $mapping;
        $session->update(['options' => $options]);
    }
}
