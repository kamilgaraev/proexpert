<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateTypeDetectionDTO;
use App\Models\ImportSession;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Detection\EstimateTypeDetector;

/**
 * New Implementation of EstimateImportService
 * Based on ImportSession and Pipeline architecture.
 */
class EstimateImportService
{
    public function __construct(
        private FileStorageService $fileStorage,
        private ImportRowMapper $rowMapper,
        private AiMappingService $aiMappingService,
        private ?\App\BusinessModules\Features\BudgetEstimates\Services\EstimateService $estimateService = null,
        private ?\App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\Factory\ParserFactory $parserFactory = null
    ) {}

    /**
     * Upload file and create Import Session.
     * Replaces old uploadFile logic.
     */
    public function uploadFile(UploadedFile $file, int $userId, int $organizationId): string
    {
        Log::info('[EstimateImport] Starting upload with new session system');
        
        // 1. Store file (physically)
        $storedFile = $this->fileStorage->store($file, $organizationId);
        
        // 2. Create Session (Database)
        $session = ImportSession::create([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'status' => 'uploading',
            'file_path' => $storedFile['path'],
            'file_name' => $storedFile['name'],
            'file_size' => $storedFile['size'],
            'file_format' => $storedFile['extension'], // Initial guess from extension
            'options' => [],
            'stats' => ['progress' => 0],
        ]);
        
        Log::info("Import session created: {$session->id}", [
            'file' => $storedFile['name'],
            'size' => $storedFile['size']
        ]);
        
        return $session->id;
    }

    public function detectEstimateType(string $sessionId): EstimateTypeDetectionDTO
    {
        $session = ImportSession::findOrFail($sessionId);
        $fullPath = $this->fileStorage->getAbsolutePath($session);
        
        $detector = new EstimateTypeDetector();
        
        try {
            if ($session->file_format === 'xml') {
                $content = file_get_contents($fullPath);
            } else {
                // Try allow loading
                $content = IOFactory::load($fullPath);
            }
            
            $result = $detector->detectAll($content);
            return EstimateTypeDetectionDTO::fromDetectorResult($result);
            
        } catch (\Exception $e) {
            Log::error("Detection failed for session {$sessionId}: " . $e->getMessage());
             throw new \RuntimeException("Detection failed: " . $e->getMessage());
        }
    }

    public function detectFormat(string $sessionId, ?int $suggestedHeaderRow = null): array
    {
        $session = ImportSession::findOrFail($sessionId);
        $fullPath = $this->fileStorage->getAbsolutePath($session);
        
        try {
            $parser = $this->parserFactory->getParser($fullPath);
            
            if ($suggestedHeaderRow !== null) {
                $structure = $parser->detectStructureFromRow($fullPath, $suggestedHeaderRow);
            } else {
                $structure = $parser->detectStructure($fullPath);
            }

            // Update session options with detected structure
            $options = $session->options ?? [];
            $options['structure'] = $structure;
            $session->update(['options' => $options]);
            
            // Get Raw Sample Rows for UI
            $sampleRows = $this->getRawSampleRows($fullPath, $structure);

            // 3. Strategic Upgrade: AI-Powered Column Detection
            $aiMapping = $this->aiMappingService->detectMapping($structure['raw_headers'] ?? [], $sampleRows);
            if ($aiMapping) {
                Log::info('[EstimateImportService] Applying AI mapping results');
                $structure['column_mapping'] = $aiMapping;
                // Update session again with enriched mapping
                $options['structure'] = $structure;
                $session->update(['options' => $options]);
            }

            return [
                'format' => 'excel_simple', 
                'detected_columns' => $structure['detected_columns'],
                'raw_headers' => $structure['raw_headers'],
                'header_row' => $structure['header_row'],
                'header_candidates' => $parser->getHeaderCandidates(),
                'sample_rows' => $sampleRows,
                'ai_mapping_applied' => (bool)$aiMapping,
            ];
        } catch (\Throwable $e) {
            Log::error("[EstimateImportService] Detect format failed for session {$sessionId}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException("Detection failed: " . $e->getMessage(), 0, $e);
        }
    }

    public function preview(string $sessionId, ?array $columnMapping = null): EstimateImportDTO
    {
        $session = ImportSession::findOrFail($sessionId);
        $fullPath = $this->fileStorage->getAbsolutePath($session);
        
        $parser = $this->parserFactory->getParser($fullPath);
        
        // Update mapping in options if provided
        $optionsBucket = $session->options ?? [];
        $structure = $optionsBucket['structure'] ?? [];
        
        if ($columnMapping) {
             $structure['column_mapping'] = $columnMapping;
             $optionsBucket['structure'] = $structure;
             $session->update(['options' => $optionsBucket]);
        } else {
             $columnMapping = $structure['column_mapping'] ?? [];
        }

        $parseOptions = [
            'column_mapping' => $columnMapping, 
            'header_row' => $structure['header_row'] ?? null
        ];
        
        // Collect items from stream to build full DTO
        $items = [];
        $sections = [];
        
        // Using getStream allows us to inject mapping options which legacy parse() didn't support well externally
        foreach ($parser->getStream($fullPath, $parseOptions) as $rowDTO) {
            // Skip technical rows (like 1, 2, 3... guide rows)
            if ($this->rowMapper->isTechnicalRow($rowDTO->rawData)) {
                Log::info("[EstimateImportService] Skipping technical row", ['row' => $rowDTO->rowNumber]);
                continue;
            }

            // Apply mapping if it's a raw stream
            $mappedDTO = $this->rowMapper->map($rowDTO, $columnMapping);
             
            // Skip rows that have no name and no numeric data (likely spacing or sub-headers we don't handle)
            if (!$mappedDTO->isSection && empty($mappedDTO->name) && $mappedDTO->quantity === null && $mappedDTO->unitPrice === null) {
                continue;
            }

            if ($mappedDTO->isSection) {
                 $sections[] = $mappedDTO->toArray();
            } else {
                 $items[] = $mappedDTO->toArray();
            }
        }
        
        // Helper to calculate totals
        $totals = [
            // Basic totals
            'total_amount' => 0, // Placeholder
            'items_count' => count($items),
        ];
        
        return new EstimateImportDTO(
             fileName: $session->file_name,
             fileSize: $session->file_size,
             fileFormat: $session->file_format,
             sections: $sections,
             items: $items,
             totals: $totals,
             metadata: [
                 'header_row' => $structure['header_row'] ?? null,
                 'total_rows' => count($items) + count($sections)
             ]
        );
    }
    
    public function analyzeMatches(string $sessionId, int $organizationId): array
    {
        // TODO: Implement in Phase 3 (Enrichment)
        return ['items' => [], 'summary' => []];
    }

    /**
     * @phase 3
     */
    public function execute(string $sessionId, array $matchingConfig, array $estimateSettings, bool $validateOnly = false): array
    {
        $session = ImportSession::findOrFail($sessionId);
        
        // Update session with execution configs
        $options = $session->options ?? [];
        $options['matching_config'] = $matchingConfig;
        $options['estimate_settings'] = $estimateSettings;
        $options['validate_only'] = $validateOnly;
        $session->update(['options' => $options, 'status' => 'queued']);
        
        // Dispatch Job
        \App\Jobs\ProcessEstimateImportJob::dispatch($sessionId, []);
        
        return [
            'status' => 'queued',
            'session_id' => $sessionId,
            'message' => 'Import job dispatched successfully.'
        ];
    }
    
    private function getRawSampleRows(string $filePath, array $structure): array
    {
        try {
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            \PhpOffice\PhpSpreadsheet\Calculation\Calculation::getInstance($spreadsheet)->disableBranchPruning();
            
            $headerRow = $structure['header_row'] ?? 0;
            $samples = [];
            $maxSamples = 5;
            $currentRow = $headerRow + 2; // Data row starts after header. Spreadsheet is 1-indexed, so 0-indexed headerRow 0 means data is row 2.
            $maxRow = min($headerRow + 20, $sheet->getHighestRow()); 
            
            $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());

            while (count($samples) < $maxSamples && $currentRow <= $maxRow) {
                $rowData = [];
                $hasData = false;
                
                for ($colIdx = 1; $colIdx <= $highestColumnIndex; $colIdx++) {
                    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
                    $cell = $sheet->getCell($colLetter . $currentRow);
                    
                    try {
                        $value = $cell->getCalculatedValue();
                    } catch (\Exception $e) {
                        $value = $cell->getValue();
                    }
                    
                    if ($value !== null && trim((string)$value) !== '') {
                        $hasData = true;
                    }
                    $rowData[] = $value; 
                }
                
                if ($hasData) {
                    $samples[] = $rowData;
                }
                $currentRow++;
            }
            
            return $samples;
        } catch (\Exception $e) {
            Log::error('[EstimateImport] Failed to get sample rows', ['error' => $e->getMessage()]);
            return [];
        }
    }
    
    /**
     * Adapter for old status polling
     */
    public function getImportStatus(string $id): array
    {
       // Check if id is a session ID (UUID)
       $session = ImportSession::find($id);
       
       if ($session) {
           return [
               'status' => $this->mapSessionStatusToOldStatus($session->status),
               'progress' => $session->stats['progress'] ?? 0,
               'error' => $session->error_message,
               'result' => $session->stats['result'] ?? null,
               'estimate_id' => $session->stats['estimate_id'] ?? null,
           ];
       }
       
       return [
           'status' => 'failed',
           'error' => 'Session not found'
       ];
    }
    
    public function getImportHistory(int $organizationId, int $limit = 50): Collection
    {
        return ImportSession::where('organization_id', $organizationId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
    
    private function mapSessionStatusToOldStatus(string $status): string
    {
        return match($status) {
            'uploading', 'detecting' => 'processing',
            'parsing', 'processing', 'enriching' => 'processing',
            'completed' => 'completed',
            'failed' => 'failed',
            default => 'queued'
        };
    }
}
