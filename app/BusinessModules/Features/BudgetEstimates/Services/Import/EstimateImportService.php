<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\BusinessModules\Features\BudgetEstimates\Contracts\EstimateImportParserInterface;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportResultDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateSectionService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\ExcelSimpleTableParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\NormativeMatchingService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\NormativeCodeService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Detection\EstimateTypeDetector;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Adapters\EstimateAdapterFactory;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportContext;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportProgressTracker;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\EstimateItemProcessor;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateTypeDetectionDTO;
use App\Models\Estimate;
use App\Models\EstimateImportHistory;
use App\Jobs\ProcessEstimateImportJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

class EstimateImportService
{
    public function __construct(
        private EstimateService $estimateService,
        private EstimateSectionService $sectionService,
        private EstimateCalculationService $calculationService,
        private ImportMappingService $mappingService,
        private ImportValidationService $validationService,
        private NormativeMatchingService $normativeMatchingService,
        private NormativeCodeService $codeService,
        private EstimateItemProcessor $itemProcessor
    ) {}

    public function uploadFile(UploadedFile $file, int $userId, int $organizationId): string
    {
        $fileId = Str::uuid()->toString();
        $extension = $file->getClientOriginalExtension();
        $originalName = $file->getClientOriginalName();
        
        // Check file is valid
        if (!$file->isValid()) {
            throw new \RuntimeException('Uploaded file is not valid');
        }
        
        // Prepare directory
        $tempPath = storage_path("app/temp/estimate-imports/{$organizationId}");
        if (!file_exists($tempPath)) {
            if (!mkdir($tempPath, 0755, true)) {
                throw new \RuntimeException('Failed to create upload directory');
            }
        }
        
        if (!is_writable($tempPath)) {
            throw new \RuntimeException('Upload directory is not writable');
        }
        
        $fileName = "{$fileId}.{$extension}";
        $fullPath = "{$tempPath}/{$fileName}";
        
        // Move file with error handling
        try {
            $file->move($tempPath, $fileName);
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to move uploaded file: ' . $e->getMessage());
        }
        
        if (!file_exists($fullPath)) {
            throw new \RuntimeException('File was not moved successfully');
        }
        
        // Cache file metadata
        $cacheKey = "estimate_import_file:{$fileId}";
        Cache::put($cacheKey, [
            'file_id' => $fileId,
            'file_path' => $fullPath,
            'file_name' => $originalName,
            'file_size' => filesize($fullPath),
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'uploaded_at' => now()->toIso8601String(),
        ], now()->addHours(24));
        
        return $fileId;
    }

    public function detectEstimateType(string $fileId): EstimateTypeDetectionDTO
    {
        Log::info('[EstimateImport] detectEstimateType started', [
            'file_id' => $fileId,
        ]);
        
        try {
            $fileData = $this->getFileData($fileId);
            $parser = $this->getParser($fileData['file_path']);
            
            $content = $parser->readContent($fileData['file_path'], maxRows: 100);
            
            $detector = new EstimateTypeDetector();
            $result = $detector->detectAll($content);
            
            $detectionDTO = EstimateTypeDetectionDTO::fromDetectorResult($result);
            
            Cache::put("estimate_import_type:{$fileId}", $detectionDTO->toArray(), now()->addHours(24));
            
            Log::info('[EstimateImport] detectEstimateType completed', [
                'file_id' => $fileId,
                'detected_type' => $detectionDTO->detectedType,
                'confidence' => $detectionDTO->confidence,
            ]);
            
            return $detectionDTO;
        } catch (\Exception $e) {
            Log::error('[EstimateImport] detectEstimateType failed', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw new \RuntimeException('Не удалось определить тип сметы: ' . $e->getMessage(), 0, $e);
        }
    }

    public function detectFormat(string $fileId, ?int $suggestedHeaderRow = null): array
    {
        Log::info('[EstimateImport] detectFormat started', [
            'file_id' => $fileId,
            'suggested_header_row' => $suggestedHeaderRow,
        ]);
        
        try {
            $fileData = $this->getFileData($fileId);
        } catch (\Exception $e) {
            Log::error('[EstimateImport] Failed to get file data', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
        
        $parser = $this->getParser($fileData['file_path']);
        
        if ($suggestedHeaderRow !== null) {
            $structure = $parser->detectStructureFromRow($fileData['file_path'], $suggestedHeaderRow);
        } else {
            $structure = $parser->detectStructure($fileData['file_path']);
        }
        
        Cache::put("estimate_import_structure:{$fileId}", $structure, now()->addHours(24));
        
        return [
            'format' => 'excel_simple',
            'detected_columns' => $structure['detected_columns'],
            'raw_headers' => $structure['raw_headers'],
            'header_row' => $structure['header_row'],
            'header_candidates' => $parser->getHeaderCandidates(),
            'sample_rows' => $this->getSampleRows($fileData['file_path'], $structure),
        ];
    }

    public function preview(string $fileId, ?array $columnMapping = null): EstimateImportDTO
    {
        Log::info('[EstimateImport] Starting preview', [
            'file_id' => $fileId,
            'has_column_mapping' => $columnMapping !== null,
        ]);
        
        $fileData = $this->getFileData($fileId);
        $parser = $this->getParser($fileData['file_path']);
        
        if ($columnMapping !== null) {
            Cache::put("estimate_import_mapping:{$fileId}", $columnMapping, now()->addHours(24));
        }
        
        $importDTO = $parser->parse($fileData['file_path']);
        
        $typeData = Cache::get("estimate_import_type:{$fileId}");
        if ($typeData && isset($typeData['detected_type'])) {
            $adapterFactory = new EstimateAdapterFactory();
            $adapter = $adapterFactory->create($typeData['detected_type']);
            
            $importDTO = $adapter->adapt($importDTO, $importDTO->metadata);
            
            $importDTO->estimateType = $typeData['detected_type'];
            $importDTO->typeConfidence = $typeData['confidence'];
        }
        
        $previewArray = $importDTO->toArray();
        Cache::put("estimate_import_preview:{$fileId}", $previewArray, now()->addHours(24));
        
        Log::info('[EstimateImport] Preview completed and cached', [
            'file_id' => $fileId,
            'items_count' => count($previewArray['items'] ?? []),
        ]);
        
        return $importDTO;
    }

    public function analyzeMatches(string $fileId, int $organizationId): array
    {
        $previewData = Cache::get("estimate_import_preview:{$fileId}");
        
        if ($previewData === null) {
            $columnMapping = Cache::get("estimate_import_mapping:{$fileId}");
            $importDTO = $this->preview($fileId, $columnMapping);
            $previewData = $importDTO->toArray();
        }
        
        $items = $previewData['items'];
        $matchResults = [];
        $workItems = array_filter($items, fn($item) => ($item['item_type'] ?? 'work') === 'work');
        
        $summary = [
            'code_exact_matches' => 0,
            'code_fuzzy_matches' => 0,
            'code_not_found' => 0,
            'items_with_codes' => 0,
            'items_without_codes' => 0,
            'name_matches' => 0,
            'name_exact_matches' => 0,
            'name_fuzzy_matches' => 0,
            'new_work_types_needed' => 0,
            'total_items' => count($items),
            'works_count' => count($workItems),
            'materials_count' => count(array_filter($items, fn($item) => ($item['item_type'] ?? '') === 'material')),
            'equipment_count' => count(array_filter($items, fn($item) => ($item['item_type'] ?? '') === 'equipment')),
            'labor_count' => count(array_filter($items, fn($item) => ($item['item_type'] ?? '') === 'labor')),
            'summary_count' => count(array_filter($items, fn($item) => ($item['item_type'] ?? '') === 'summary')),
        ];
        
        foreach ($workItems as $item) {
            $importedText = $item['item_name'];
            $code = $item['code'] ?? null;
            
            $matchResult = null;
            
            if (!empty($code)) {
                $summary['items_with_codes']++;
                $normativeMatch = $this->normativeMatchingService->findByCode($code, [
                    'fallback_to_name' => true,
                    'name' => $importedText,
                ]);
                
                if ($normativeMatch) {
                    if ($normativeMatch['confidence'] === 100) {
                        $summary['code_exact_matches']++;
                    } elseif ($normativeMatch['method'] === 'name_match') {
                        $summary['name_matches']++;
                    } else {
                        $summary['code_fuzzy_matches']++;
                    }
                    
                    $matchResult = [
                        'imported_text' => $importedText,
                        'code' => $code,
                        'match_type' => $normativeMatch['method'] === 'name_match' ? 'name' : 'code',
                        'matched_normative' => [
                            'id' => $normativeMatch['normative']->id,
                            'code' => $normativeMatch['normative']->code,
                            'name' => $normativeMatch['normative']->name,
                            'base_price' => (float) $normativeMatch['normative']->base_price,
                        ],
                        'confidence' => $normativeMatch['confidence'],
                        'method' => $normativeMatch['method'],
                        'should_create' => false,
                    ];
                } else {
                    $summary['code_not_found']++;
                    $matchResult = [
                        'imported_text' => $importedText,
                        'code' => $code,
                        'match_type' => 'code_not_found',
                        'matched_normative' => null,
                        'confidence' => 0,
                        'method' => 'code_search_failed',
                        'should_create' => true,
                        'warning' => 'Код работы не найден в справочнике нормативов',
                    ];
                }
            } else {
                $summary['items_without_codes']++;
                $nameResults = $this->normativeMatchingService->findByName($importedText, 1);
                
                if ($nameResults->isNotEmpty()) {
                    $nameMatch = $nameResults->first();
                    $summary['name_matches']++;
                    
                    $matchResult = [
                        'imported_text' => $importedText,
                        'code' => null,
                        'match_type' => 'name',
                        'matched_normative' => [
                            'id' => $nameMatch['normative']->id,
                            'code' => $nameMatch['normative']->code,
                            'name' => $nameMatch['normative']->name,
                            'base_price' => (float) $nameMatch['normative']->base_price,
                        ],
                        'confidence' => $nameMatch['confidence'],
                        'method' => $nameMatch['method'],
                        'should_create' => false,
                    ];
                } else {
                    $matchResult = [
                        'imported_text' => $importedText,
                        'code' => null,
                        'match_type' => 'name_not_found',
                        'matched_normative' => null,
                        'confidence' => 0,
                        'method' => 'name_search_failed',
                        'should_create' => true,
                        'warning' => 'Код отсутствует, не найдено совпадений по названию',
                    ];
                    $summary['new_work_types_needed']++;
                }
            }
            
            if ($matchResult) {
                $matchResults[] = $matchResult;
            }
        }
        
        Cache::put("estimate_import_matches:{$fileId}", [
            'match_results' => $matchResults,
            'summary' => $summary,
        ], now()->addHours(24));
        
        return [
            'items' => $matchResults,
            'summary' => $summary,
        ];
    }

    public function execute(string $fileId, array $matchingConfig, array $estimateSettings): array
    {
        $previewData = Cache::get("estimate_import_preview:{$fileId}");
        
        if ($previewData === null) {
            throw new \Exception('Preview data not found');
        }
        
        $itemsCount = count($previewData['items']);
        $jobId = Str::uuid()->toString();
        
        Log::info('[EstimateImport] 🚀 Начало импорта', [
            'file_id' => $fileId,
            'items_count' => $itemsCount,
            'job_id' => $jobId,
            'import_type' => $itemsCount <= 500 ? 'sync' : 'async',
        ]);
        
        if ($itemsCount <= 500) {
            return $this->syncImport($fileId, $matchingConfig, $estimateSettings, $jobId);
        } else {
            return $this->queueImport($fileId, $matchingConfig, $estimateSettings);
        }
    }

    public function syncImport(string $fileId, array $matchingConfig, array $estimateSettings, ?string $jobId = null): array
    {
        $startTime = microtime(true);
        $fileData = $this->getFileData($fileId);
        $previewData = Cache::get("estimate_import_preview:{$fileId}");
        
        $importDTO = new EstimateImportDTO(
            fileName: $previewData['file_name'],
            fileSize: $previewData['file_size'],
            fileFormat: $previewData['file_format'],
            sections: $previewData['sections'],
            items: $previewData['items'],
            totals: $previewData['totals'],
            metadata: $previewData['metadata']
        );
        
        // Создаем трекер прогресса
        $progressTracker = new ImportProgressTracker($jobId);
        
        if ($jobId) {
            EstimateImportHistory::create([
                'organization_id' => $fileData['organization_id'],
                'user_id' => $fileData['user_id'],
                'job_id' => $jobId,
                'file_name' => $fileData['file_name'],
                'file_path' => $fileData['file_path'],
                'file_size' => $fileData['file_size'],
                'file_format' => $this->detectFileFormat($fileData['file_path']),
                'status' => 'processing',
                'progress' => 0,
            ]);
        }
        
        try {
            $result = $this->createEstimateFromImport($importDTO, $matchingConfig, $estimateSettings, $progressTracker, $jobId);
            
            $processingTime = (microtime(true) - $startTime) * 1000;
            $result->processingTimeMs = (int)$processingTime;
            
            if ($jobId) {
                EstimateImportHistory::where('job_id', $jobId)->update([
                    'status' => 'completed',
                    'estimate_id' => $result->estimateId,
                    'items_imported' => $result->itemsImported,
                    'result_log' => $result->toArray(),
                    'processing_time_ms' => $result->processingTimeMs,
                    'progress' => 100,
                ]);
            }
            
            $this->cleanup($fileId);
            
            return [
                'status' => 'completed',
                'job_id' => $jobId,
                'estimate_id' => $result->estimateId,
                'result' => $result->toArray(),
            ];
            
        } catch (\Exception $e) {
            if ($jobId) {
                EstimateImportHistory::where('job_id', $jobId)->update([
                    'status' => 'failed',
                    'result_log' => ['error' => $e->getMessage()],
                ]);
            }
            throw $e;
        }
    }

    public function queueImport(string $fileId, array $matchingConfig, array $estimateSettings): array
    {
        $fileData = $this->getFileData($fileId);
        $jobId = Str::uuid()->toString();
        
        EstimateImportHistory::create([
            'organization_id' => $fileData['organization_id'],
            'user_id' => $fileData['user_id'],
            'file_name' => $fileData['file_name'],
            'file_path' => $fileData['file_path'],
            'file_size' => $fileData['file_size'],
            'file_format' => $this->detectFileFormat($fileData['file_path']),
            'status' => 'queued',
            'job_id' => $jobId,
            'progress' => 0,
        ]);
        
        ProcessEstimateImportJob::dispatch(
            $fileId,
            $fileData['user_id'],
            $fileData['organization_id'],
            $matchingConfig,
            $estimateSettings,
            $jobId
        )->onQueue('imports');
        
        $projectId = $estimateSettings['project_id'] ?? null;
        $statusUrl = $projectId 
            ? "/api/v1/admin/projects/{$projectId}/estimates/import/status/{$jobId}"
            : "/api/v1/admin/estimates/import/status/{$jobId}";
        
        return [
            'status' => 'processing',
            'job_id' => $jobId,
            'estimated_completion' => now()->addMinutes(5)->toIso8601String(),
            'check_status_url' => $statusUrl,
        ];
    }

    public function getImportStatus(string $jobId): array
    {
        $history = EstimateImportHistory::where('job_id', $jobId)->first();
        
        if (!$history) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException(
                "EstimateImportHistory not found for job_id: {$jobId}"
            );
        }
        
        return [
            'status' => $history->status,
            'progress' => $history->progress,
            'estimate_id' => $history->estimate_id,
            'result' => $history->result_log,
            'error' => $history->status === 'failed' ? ($history->result_log['error'] ?? 'Unknown error') : null,
        ];
    }

    public function getImportHistory(int $organizationId, int $limit = 50): Collection
    {
        return EstimateImportHistory::where('organization_id', $organizationId)
            ->with(['user', 'estimate'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    private function createEstimateFromImport(
        EstimateImportDTO $importDTO,
        array $matchingConfig,
        array $settings,
        ImportProgressTracker $progressTracker,
        ?string $jobId = null
    ): EstimateImportResultDTO {
        DB::beginTransaction();
        
        try {
            $progressTracker->update(10, 100, 0, 10);
            
            $estimate = $this->estimateService->create([
                'name' => $settings['name'],
                'type' => $settings['type'],
                'project_id' => $settings['project_id'] ?? null,
                'contract_id' => $settings['contract_id'] ?? null,
                'organization_id' => $settings['organization_id'],
                'status' => 'draft',
                'estimate_date' => now()->toDateString(),
            ]);
            
            $progressTracker->update(25, 100, 0, 25);
            
            // Создание разделов
            $sectionsMap = $this->createSections($estimate, $importDTO->sections);
            
            $progressTracker->update(50, 100, 0, 50);
            
            // Инициализация контекста импорта
            $context = new ImportContext(
                $settings['organization_id'],
                $estimate,
                $settings,
                $matchingConfig,
                $jobId
            );
            $context->sectionsMap = $sectionsMap;
            
            // Обработка позиций с использованием Processor
            $itemsResult = $this->itemProcessor->processItems(
                $estimate,
                $importDTO->items,
                $context,
                $progressTracker
            );
            
            $progressTracker->update(85, 100, 0, 85);
            
            $this->calculationService->recalculateAll($estimate);
            
            $progressTracker->update(95, 100, 0, 95);
            
            DB::commit();
            
            return new EstimateImportResultDTO(
                estimateId: $estimate->id,
                itemsTotal: count($importDTO->items),
                itemsImported: $itemsResult['imported'],
                itemsSkipped: $itemsResult['skipped'],
                sectionsCreated: count($sectionsMap),
                newWorkTypesCreated: [], // Больше не отслеживается
                warnings: $this->validationService->generateWarnings($importDTO),
                errors: [],
                status: 'completed'
            );
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function createSections(Estimate $estimate, array $sections): array
    {
        $sectionsMap = [];
        
        foreach ($sections as $sectionData) {
            if (empty($sectionData['section_number'])) {
                continue;
            }
            
            $parentId = null;
            
            if ($sectionData['level'] > 0) {
                $parentPath = $this->getParentPath($sectionData['section_number']);
                $parentId = $sectionsMap[$parentPath] ?? null;
            }
            
            $section = $this->sectionService->createSection([
                'estimate_id' => $estimate->id,
                'section_number' => $sectionData['section_number'],
                'name' => $sectionData['item_name'],
                'parent_section_id' => $parentId,
            ]);
            
            $sectionsMap[$sectionData['section_number']] = $section->id;
        }
        
        return $sectionsMap;
    }

    private function getParentPath(string $sectionNumber): ?string
    {
        $parts = explode('.', rtrim($sectionNumber, '.'));
        
        if (count($parts) <= 1) {
            return null;
        }
        
        array_pop($parts);
        return implode('.', $parts);
    }

    private function getSampleRows(string $filePath, array $structure): array
    {
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            
            $headerRow = $structure['header_row'] ?? null;
            if ($headerRow === null) {
                return [];
            }
            
            $samples = [];
            $maxSamples = 5;
            $currentRow = $headerRow + 1;
            $maxRow = min($headerRow + 20, $sheet->getHighestRow()); 
            
            while (count($samples) < $maxSamples && $currentRow <= $maxRow) {
                $rowData = [];
                $hasData = false;
                
                foreach (range('A', $sheet->getHighestColumn()) as $col) {
                    $value = $sheet->getCell($col . $currentRow)->getValue();
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

    private function getParser(string $filePath): EstimateImportParserInterface
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        return match ($extension) {
            'xml' => new \App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\GrandSmetaXMLParser(),
            'csv' => new \App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\LocalEstimateCSVParser(),
            'txt', 'rik' => new \App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\RIKParser(),
            default => new ExcelSimpleTableParser(),
        };
    }

    private function getFileData(string $fileId): array
    {
        $cacheKey = "estimate_import_file:{$fileId}";
        $data = Cache::get($cacheKey);
        
        if ($data === null) {
            throw new \Exception("File data not found for ID: {$fileId}");
        }
        
        return $data;
    }

    private function detectFileFormat(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        return match ($extension) {
            'xlsx', 'xls' => 'excel_simple',
            'csv' => 'csv',
            'xml' => 'xml',
            default => 'unknown',
        };
    }

    private function cleanup(string $fileId): void
    {
        $fileData = $this->getFileData($fileId);
        
        if (file_exists($fileData['file_path'])) {
            @unlink($fileData['file_path']);
        }
        
        Cache::forget("estimate_import_file:{$fileId}");
        Cache::forget("estimate_import_structure:{$fileId}");
        Cache::forget("estimate_import_preview:{$fileId}");
        Cache::forget("estimate_import_mapping:{$fileId}");
        Cache::forget("estimate_import_matches:{$fileId}");
    }
}
