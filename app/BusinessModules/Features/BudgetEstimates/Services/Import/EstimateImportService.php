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
use App\BusinessModules\Features\BudgetEstimates\Services\Import\StyleExtractor;

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
        private StyleExtractor $styleExtractor,
        private TemplateService $templateService,
        private ImportFormatOrchestrator $orchestrator,
        private SignatureGenerator $signatureGenerator,
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
        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        
        try {
            if ($extension === 'xml') {
                $content = file_get_contents($fullPath);
            } else {
                $content = IOFactory::load($fullPath);
            }

            // 🧠 Memory Lookup (Signature-based)
            $dto = new EstimateTypeDetectionDTO();
            if ($content instanceof \PhpOffice\PhpSpreadsheet\Spreadsheet) {
                try {
                    $sheet = $content->getActiveSheet();
                    $firstRow = [];
                    foreach ($sheet->getRowIterator(1, 1) as $row) {
                        foreach ($row->getCellIterator() as $cell) {
                            $firstRow[] = $cell->getValue();
                        }
                    }
                    
                    $signature = $this->signatureGenerator->generate($firstRow);
                    $memory = \App\Models\ImportMemory::where('organization_id', $session->organization_id)
                        ->where('signature', $signature)
                        ->orderByDesc('success_count')
                        ->first();

                    if ($memory) {
                        Log::info("[EstimateImport] Memory match found for signature: {$signature}");
                        $dto->confidence = 0.95;
                        $dto->detectedType = 'prohelper'; // Or specialized memory type if added
                        $dto->indicators['memory_id'] = $memory->id;
                        
                        // Store memory info in session
                        $options = $session->options ?? [];
                        $options['memory_id'] = $memory->id;
                        $options['column_mapping'] = $memory->column_mapping;
                        $session->update(['options' => $options]);
                    }
                } catch (\Throwable $e) {
                    Log::warning("[EstimateImport] Memory lookup failed (non-critical): " . $e->getMessage());
                }
            }
            
            // 🏗️ If no high-confidence memory, Use Modular Orchestrator
            if ($dto->confidence < 0.9) {
                $handler = $this->orchestrator->detectHandler($content, $extension);
                
                if ($handler) {
                    Log::info("[EstimateImport] Handler detected: {$handler->getSlug()}");
                    $handlerDto = $handler->canHandle($content, $extension);
                    
                    if ($handlerDto->confidence > $dto->confidence) {
                        $dto = $handlerDto;
                    }
                    
                    // Store format in session
                    $options = $session->options ?? [];
                    $options['format_handler'] = $handler->getSlug();
                    $session->update(['options' => $options]);
                }
            }

            if (!$dto->detectedType) {
                Log::warning("[EstimateImport] No specific handler or memory detected for session {$sessionId}");
                $dto->confidence = 0.0;
            }
 
             // Template Detection (Highest priority)
            if ($content instanceof \PhpOffice\PhpSpreadsheet\Spreadsheet) {
                $description = $content->getProperties()->getDescription();
                if (str_contains($description, 'PROHELPER_TEMPLATE')) {
                     $dto->indicators['is_template'] = true;
                     $dto->confidence = 1.0;
                     $dto->detectedType = 'prohelper';
                     
                     $options = $session->options ?? [];
                     $options['is_template'] = true;
                     $options['format_handler'] = 'generic'; // Template is generic but fixed mapping
                     $session->update(['options' => $options]);
                }
            }
 
             return $dto;
            
        } catch (\Exception $e) {
            Log::error("Detection failed for session {$sessionId}: " . $e->getMessage());
             throw new \RuntimeException("Detection failed: " . $e->getMessage());
        }
    }

    public function detectFormat(string $sessionId, ?int $suggestedHeaderRow = null): array
    {
        $session = ImportSession::findOrFail($sessionId);
        $fullPath = $this->fileStorage->getAbsolutePath($session);
        
        // 🏗 Check for handler
        $options = $session->options ?? [];
        $handlerSlug = $options['format_handler'] ?? 'generic';

        if ($handlerSlug === 'grandsmeta') {
            Log::info("[EstimateImport] Using GrandSmeta fixed format detection");
            
            $content = IOFactory::load($fullPath);
            $sheet = $content->getActiveSheet();
            
            $handler = new \App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\GrandSmeta\GrandSmetaHandler();
            $detection = $handler->findHeaderAndMapping($sheet);
            $headerRow = $detection['header_row'];
            $mapping = $detection['mapping'];
            
            $structure = [
                'header_row' => $headerRow,
                'detected_columns' => array_flip($mapping),
                'raw_headers' => [], // Text headers are not strictly needed when numeric mapping is used
                'column_mapping' => $mapping
            ];
            
            $options['structure'] = $structure;
            $session->update(['options' => $options]);
            
            return array_merge(['format' => 'excel_simple'], $structure, [
                 'header_candidates' => [$headerRow],
                 'sample_rows' => $this->getRawSampleRows($fullPath, $structure),
                 'ai_mapping_applied' => false
            ]);
        }

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

            // 3. Strategic Upgrade: AI-Powered Column Detection or Template Mapping
            $options = $session->options ?? [];
            $isTemplate = $options['is_template'] ?? false;
            
            if ($isTemplate) {
                 Log::info('[EstimateImportService] Applying fixed template mapping');
                 $structure['column_mapping'] = $this->parserFactory->getSmartMappingService()->applyTemplateMapping();
                 $aiResponse = true; // Mark as applied
            } else {
                 $aiResponse = $this->aiMappingService->detectMapping($structure['raw_headers'] ?? [], $sampleRows);
            }

            if ($aiResponse && isset($aiResponse['mapping'])) {
                Log::info('[EstimateImportService] Applying AI mapping results');
                $structure['column_mapping'] = $aiResponse['mapping'];
                $structure['ai_section_hints'] = $aiResponse['section_hints'] ?? [];

                $options['structure'] = $structure;
                $session->update(['options' => $options]);
            }

            $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            if (in_array($extension, ['xlsx', 'xls', 'xlsm'], true)) {
                try {
                    $headerRow   = $structure['header_row'] ?? 1;
                    $rawStyles   = $this->styleExtractor->extractStyles($fullPath, max(1, $headerRow), 300);
                    $rowStyles   = $this->styleExtractor->summarizeRowStyles($rawStyles);
                    $structure['row_styles'] = $rowStyles;
                    $options['structure']    = $structure;
                    $session->update(['options' => $options]);
                    Log::info('[EstimateImportService] Row styles extracted', ['rows' => count($rowStyles)]);
                } catch (\Throwable $e) {
                    Log::warning('[EstimateImportService] StyleExtractor failed (non-critical): ' . $e->getMessage());
                }
            }

            return [
                'format' => 'excel_simple', 
                'detected_columns' => $structure['detected_columns'],
                'raw_headers' => $structure['raw_headers'],
                'header_row' => $structure['header_row'],
                'header_candidates' => $parser->getHeaderCandidates(),
                'sample_rows' => $sampleRows,
                'ai_mapping_applied' => (bool)$aiResponse,
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
        
        // 🏗️ Handlers are now the primary way to parse
        $optionsBucket = $session->options ?? [];
        $handlerSlug = $optionsBucket['format_handler'] ?? 'generic';
        
        try {
            $handler = $this->orchestrator->getHandler($handlerSlug);
            Log::info("[EstimateImport] Delegating preview to handler: {$handlerSlug}");
            
            // Apply column mapping override if provided
            if ($columnMapping) {
                $handler->applyMapping($session, $columnMapping);
                $optionsBucket = $session->fresh()->options; // Refresh options
            }
            
            $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $content = ($extension === 'xml') ? file_get_contents($fullPath) : IOFactory::load($fullPath);
            
            $result = $handler->parse($session, $content);
            $items = $result['items'] ?? [];
            $sections = $result['sections'] ?? [];

            // Calculate totals
            $totalAmount = 0;
            foreach ($items as $item) {
                $q = $item['quantity'] ?? 0;
                $p = $item['unit_price'] ?? 0;
                $totalAmount += $item['current_total_amount'] ?? ($q * $p);
            }

            return new EstimateImportDTO(
                 fileName: $session->file_name,
                 fileSize: $session->file_size,
                 fileFormat: $session->file_format,
                 sections: $sections,
                 items: $items,
                 totals: [
                     'total_amount' => $totalAmount,
                     'items_count' => count($items),
                 ],
                 metadata: [
                     'handler' => $handlerSlug,
                     'header_row' => $optionsBucket['structure']['header_row'] ?? null,
                     'total_rows' => count($items) + count($sections)
                 ]
            );
        } catch (\Throwable $e) {
            Log::error("[EstimateImport] Handler delegation failed: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException("Preview failed: " . $e->getMessage());
        }
    }

    /**
     * Capture the current successful state into memory for future use.
     */
    public function learnFromSession(ImportSession $session): void
    {
        try {
            $fullPath = $this->fileStorage->getAbsolutePath($session);
            $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            
            if ($extension === 'xml') return; // XML memory not implemented yet

            $content = IOFactory::load($fullPath);
            $sheet = $content->getActiveSheet();
            
            $firstRow = [];
            foreach ($sheet->getRowIterator(1, 1) as $row) {
                foreach ($row->getCellIterator() as $cell) {
                    $firstRow[] = $cell->getValue();
                }
            }
            
            $signature = $this->signatureGenerator->generate($firstRow);
            $mapping = $session->options['column_mapping'] ?? ($session->options['structure']['column_mapping'] ?? []);
            
            if (empty($mapping)) return;

            \App\Models\ImportMemory::updateOrCreate(
                [
                    'organization_id' => $session->organization_id,
                    'signature' => $signature,
                ],
                [
                    'user_id' => $session->user_id,
                    'file_format' => $extension,
                    'original_headers' => $firstRow,
                    'column_mapping' => $mapping,
                    'header_row' => $session->options['structure']['header_row'] ?? null,
                    'last_used_at' => now(),
                    'usage_count' => \Illuminate\Support\Facades\DB::raw('usage_count + 1'),
                ]
            );
            
            Log::info("[EstimateImport] Learned new structure signature: {$signature}");
        } catch (\Throwable $e) {
            Log::warning("[EstimateImport] Learning failed: " . $e->getMessage());
        }
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
    
    public function downloadTemplate(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return $this->templateService->generate();
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
