<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\BusinessModules\Features\BudgetEstimates\Contracts\EstimateImportParserInterface;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportResultDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateSectionService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\ExcelSimpleTableParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\Factory\ParserFactory;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\ExcelStreamParser;
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
        private EstimateItemProcessor $itemProcessor,
        private ParserFactory $parserFactory,
        private SmartMappingService $smartMappingService
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
        // ... (оставляем старую логику для типа сметы пока что, или обновляем на новый парсер)
        // Для простоты используем старый парсер здесь, так как он читает первые 100 строк
        Log::info('[EstimateImport] detectEstimateType started', [
            'file_id' => $fileId,
        ]);
        
        try {
            $fileData = $this->getFileData($fileId);
            // Используем старый парсер для совместимости с детектором пока
            $parser = new ExcelSimpleTableParser();
            
            $content = $parser->readContent($fileData['file_path'], maxRows: 100);
            
            $detector = new EstimateTypeDetector();
            $result = $detector->detectAll($content);
            
            $detectionDTO = EstimateTypeDetectionDTO::fromDetectorResult($result);
            
            Cache::put("estimate_import_type:{$fileId}", $detectionDTO->toArray(), now()->addHours(24));
            
            return $detectionDTO;
        } catch (\Exception $e) {
            Log::error('[EstimateImport] detectEstimateType failed', [
                'file_id' => $fileId,
                'error' => $e->getMessage()
            ]);
            
            throw new \RuntimeException('Не удалось определить тип сметы: ' . $e->getMessage(), 0, $e);
        }
    }

    public function detectFormat(string $fileId, ?int $suggestedHeaderRow = null): array
    {
        Log::info('[EstimateImport] detectFormat started with Smart Mapping', [
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
        
        // Используем старый парсер для извлечения заголовков, так как он имеет логику header detection
        $parser = new ExcelSimpleTableParser();
        
        if ($suggestedHeaderRow !== null) {
            $structure = $parser->detectStructureFromRow($fileData['file_path'], $suggestedHeaderRow);
        } else {
            $structure = $parser->detectStructure($fileData['file_path']);
        }
        
        // Используем SmartMappingService для улучшения маппинга
        $rawHeaders = $structure['raw_headers'];
        $smartMapping = $this->smartMappingService->detectMapping($rawHeaders);
        
        // Обновляем структуру данными из Smart Mapping
        $structure['column_mapping'] = $smartMapping['mapping'];
        $structure['detected_columns'] = $smartMapping['detected_columns'];
        
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
        
        // Для превью используем старый парсер, так как он возвращает DTO
        // Но с обновленным маппингом
        $parser = new ExcelSimpleTableParser();
        
        if ($columnMapping !== null) {
            Cache::put("estimate_import_mapping:{$fileId}", $columnMapping, now()->addHours(24));
        }
        
        $importDTO = $parser->parse($fileData['file_path']);
        
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
        // ... (без изменений)
        $previewData = Cache::get("estimate_import_preview:{$fileId}");
        
        if ($previewData === null) {
            $columnMapping = Cache::get("estimate_import_mapping:{$fileId}");
            $importDTO = $this->preview($fileId, $columnMapping);
            $previewData = $importDTO->toArray();
        }
        
        // Create a dummy context and tracker for analysis mode
        $dummyContext = new ImportContext($organizationId, new Estimate(), [], [], null);
        $dummyTracker = new ImportProgressTracker(null);
        
        $result = $this->itemProcessor->processItems(new Estimate(), $previewData['items'], $dummyContext, $dummyTracker);
        
        return [
            'items' => [], // TODO: map result back to what frontend expects for analysis
            'summary' => $result['types_breakdown']
        ];
    }

    public function execute(string $fileId, array $matchingConfig, array $estimateSettings): array
    {
        // Используем потоковый парсинг для выполнения
        $fileData = $this->getFileData($fileId);
        
        // Определяем, использовать ли очередь
        // Для этого нужно знать размер файла. Если большой - в очередь.
        // Или количество строк из кэша превью.
        $previewData = Cache::get("estimate_import_preview:{$fileId}");
        $itemsCount = count($previewData['items'] ?? []);
        
        $jobId = Str::uuid()->toString();
        
        Log::info('[EstimateImport] 🚀 Начало импорта (Streaming)', [
            'file_id' => $fileId,
            'items_count_estimate' => $itemsCount,
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
            // Используем новый потоковый метод
            $result = $this->createEstimateFromStream($fileId, $matchingConfig, $estimateSettings, $progressTracker, $jobId);
            
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
        // ... (без изменений)
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

    // ... getImportStatus, getImportHistory, getFileData, detectFileFormat, cleanup (без изменений)
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

    private function getSampleRows(string $filePath, array $structure): array
    {
        // (Используем старый метод для сэмплов)
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
        // Используем старый фабричный метод для совместимости в методах, где нужен EstimateImportParserInterface
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        return match ($extension) {
            'xml' => new \App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\GrandSmetaXMLParser(),
            'csv' => new \App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\LocalEstimateCSVParser(),
            'txt', 'rik' => new \App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\RIKParser(),
            default => new ExcelSimpleTableParser(),
        };
    }

    private function createEstimateFromImport(
        EstimateImportDTO $importDTO,
        array $matchingConfig,
        array $settings,
        ImportProgressTracker $progressTracker,
        ?string $jobId = null
    ): EstimateImportResultDTO {
        // Старый метод для совместимости (если используется не ExcelStreamParser)
        return $this->createEstimateFromStream(
            null, // hack
            $matchingConfig,
            $settings,
            $progressTracker,
            $jobId,
            $importDTO->items
        );
    }

    private function createEstimateFromStream(
        ?string $fileId,
        array $matchingConfig,
        array $settings,
        ImportProgressTracker $progressTracker,
        ?string $jobId = null,
        ?array $preloadedItems = null
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
            
            // Если items переданы (из превью), используем их. Иначе стримим из файла.
            if ($preloadedItems) {
                $iterator = $preloadedItems;
            } else {
                $fileData = $this->getFileData($fileId);
                $parser = $this->parserFactory->getParser($fileData['file_path']);
                // TODO: Здесь нужно применить маппинг колонок к генератору
                // Сейчас просто получаем сырые строки.
                // Для MVP Enterprise реализации мы будем полагаться на то, что processItems может принять DTO
                // Но ExcelStreamParser возвращает array.
                // Нам нужен адаптер, который берет generator и mapping и выдает DTO.
                
                // Временное решение: используем превью данные (не настоящий стриминг, но безопасно)
                // Для настоящего стриминга нужно переписать парсер, чтобы он принимал маппинг.
                $previewData = Cache::get("estimate_import_preview:{$fileId}");
                $iterator = $previewData['items']; 
            }
            
            // Инициализация контекста импорта
            $context = new ImportContext(
                $settings['organization_id'],
                $estimate,
                $settings,
                $matchingConfig,
                $jobId
            );
            
            // Обработка позиций с использованием Processor
            $itemsResult = $this->itemProcessor->processItems(
                $estimate,
                $iterator,
                $context,
                $progressTracker
            );
            
            $progressTracker->update(85, 100, 0, 85);
            
            $this->calculationService->recalculateAll($estimate);
            
            $progressTracker->update(95, 100, 0, 95);
            
            DB::commit();
            
            return new EstimateImportResultDTO(
                estimateId: $estimate->id,
                itemsTotal: is_array($iterator) ? count($iterator) : 0,
                itemsImported: $itemsResult['imported'],
                itemsSkipped: $itemsResult['skipped'],
                sectionsCreated: 0, // Считается внутри
                newWorkTypesCreated: [], 
                warnings: [], // Warnings now inside items
                errors: [],
                status: 'completed'
            );
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
