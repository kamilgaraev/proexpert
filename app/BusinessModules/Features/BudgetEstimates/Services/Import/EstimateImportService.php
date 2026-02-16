<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\BusinessModules\Features\BudgetEstimates\Contracts\EstimateImportParserInterface;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportResultDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateSectionService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\ExcelSimpleTableParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\ProhelperEstimateParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\Factory\ParserFactory;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\ExcelStreamParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\UniversalXmlParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\NormativeMatchingService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\NormativeCodeService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Detection\EstimateTypeDetector;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Adapters\EstimateAdapterFactory;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportContext;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportProgressTracker;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\EstimateItemProcessor;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Classification\ItemClassificationService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Calculation\AICalculationService;
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
        private SmartMappingService $smartMappingService,
        private ItemClassificationService $classificationService,
        private ?AICalculationService $aiCalculationService = null
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
            $extension = strtolower(pathinfo($fileData['file_path'], PATHINFO_EXTENSION));
            
            $content = null;

            // Специальная обработка для XML файлов
            // Мы НЕ хотим пытаться грузить их через IOFactory, так как это дорого и может вызывать ошибки
            // или возвращать некорректные Spreadsheet объекты для XML структур
            if ($extension === 'xml') {
                Log::info('[EstimateImport] XML file detected, reading raw content directly', ['file_id' => $fileId]);
                if (file_exists($fileData['file_path'])) {
                    $content = file_get_contents($fileData['file_path']);
                    if ($content === false) {
                        Log::error('[EstimateImport] Failed to read XML file contents', ['file_id' => $fileId]);
                        throw new \RuntimeException('Failed to read file contents from disk');
                    }
                } else {
                    throw new \RuntimeException('File not found at path: ' . $fileData['file_path']);
                }
            } else {
                // Для остальных файлов (Excel, CSV) пробуем IOFactory
                try {
                    // Загружаем полный Spreadsheet объект для детектора
                    // ProhelperDetector нуждается в доступе ко всем листам, включая скрытые
                    $content = \PhpOffice\PhpSpreadsheet\IOFactory::load($fileData['file_path']);
                } catch (\Exception $e) {
                    Log::warning('[EstimateImport] Failed to load file as Spreadsheet, falling back to raw content', [
                        'file_id' => $fileId,
                        'error' => $e->getMessage()
                    ]);
                    
                    // Если не удалось загрузить как Excel, читаем как текст/XML
                    // Это нужно для XML форматов (ГрандСмета и др.), которые PhpSpreadsheet может не поддерживать
                    if (file_exists($fileData['file_path'])) {
                        $content = file_get_contents($fileData['file_path']);
                        
                        if ($content === false) {
                            Log::error('[EstimateImport] Failed to read file contents', ['file_id' => $fileId]);
                            throw new \RuntimeException('Failed to read file contents from disk');
                        }
                    }
                }
            }
            
            if ($content === null) {
                throw new \RuntimeException('Failed to load file content');
            }
            
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
        
        // Выбираем парсер в зависимости от типа файла
        $parser = $this->getParser($fileData['file_path']);
        
        if ($suggestedHeaderRow !== null) {
            $structure = $parser->detectStructureFromRow($fileData['file_path'], $suggestedHeaderRow);
        } else {
            $structure = $parser->detectStructure($fileData['file_path']);
        }
        
        // Используем SmartMappingService только если есть заголовки (для Excel/CSV)
        $rawHeaders = $structure['raw_headers'] ?? [];
        if (!empty($rawHeaders)) {
            $smartMapping = $this->smartMappingService->detectMapping($rawHeaders);
            
            // Обновляем структуру данными из Smart Mapping
            $structure['column_mapping'] = $smartMapping['mapping'];
            $structure['detected_columns'] = $smartMapping['detected_columns'];
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
        Log::info('[EstimateImport] Starting preview with AI classification', [
            'file_id' => $fileId,
            'has_column_mapping' => $columnMapping !== null,
        ]);
        
        $fileData = $this->getFileData($fileId);
        
        // Для превью используем старый парсер, так как он возвращает DTO
        // Но с обновленным маппингом
        $parser = $this->getParser($fileData['file_path']);
        
        if ($columnMapping !== null) {
            Cache::put("estimate_import_mapping:{$fileId}", $columnMapping, now()->addHours(24));
        }
        
        $importDTO = $parser->parse($fileData['file_path']);
        
        // 🤖 ДОБАВЛЯЕМ AI КЛАССИФИКАЦИЮ ДЛЯ PREVIEW
        Log::info('[EstimateImport] Applying AI classification to preview items', [
            'items_count' => count($importDTO->items),
        ]);
        
        $itemsToClassify = [];
        foreach ($importDTO->items as $index => $item) {
            $itemsToClassify[$index] = [
                'code' => $item['code'] ?? null,
                'name' => $item['item_name'] ?? '',
                'unit' => $item['unit'] ?? null,
                'price' => $item['unit_price'] ?? null,
            ];
        }
        
        try {
            $classificationResults = $this->classificationService->classifyBatch($itemsToClassify);
            
            // Применяем результаты классификации к items
            foreach ($importDTO->items as $index => &$item) {
                if (isset($classificationResults[$index])) {
                    $result = $classificationResults[$index];
                    $item['item_type'] = $result->type;
                    $item['confidence_score'] = $result->confidenceScore;
                    $item['classification_source'] = $result->source;
                }
            }
            unset($item); // Разрываем ссылку
            
            Log::info('[EstimateImport] AI classification completed', [
                'classified_items' => count($classificationResults),
            ]);
        } catch (\Throwable $e) {
            Log::error('[EstimateImport] AI classification failed, skipping', [
                'error' => $e->getMessage()
            ]);
            // Continue without classification
        }
        
        // 🤖 АВТОМАТИЧЕСКИЙ РАСЧЕТ И ПРОВЕРКА СУММ
        if ($this->aiCalculationService) {
            Log::info('[EstimateImport] Applying AI calculation validation');
            $importDTO->items = $this->aiCalculationService->validateAndCalculate($importDTO->items);
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

    public function execute(string $fileId, array $matchingConfig, array $estimateSettings, bool $validateOnly = false): array
    {
        // Используем потоковый парсинг для выполнения
        $fileData = $this->getFileData($fileId);
        
        // Определяем, использовать ли очередь
        // Для этого нужно знать размер файла. Если большой - в очередь.
        // Или количество строк из кэша превью.
        $previewData = Cache::get("estimate_import_preview:{$fileId}");
        
        $shouldQueue = false;
        $itemsCount = count($previewData['items'] ?? []);
        
        // Если кэша нет ИЛИ в нем нет записей (что странно для нормальной сметы), пробуем восстановить
        if (empty($previewData) || $itemsCount === 0) {
            // Если файл > 1MB (примерно 500-1000 строк), сразу в очередь без парсинга
            if ($fileData['file_size'] > 1024 * 1024) {
                Log::warning('[EstimateImport] Preview cache missing or empty for large file, forcing async import', [
                    'file_id' => $fileId,
                    'file_size' => $fileData['file_size'],
                    'items_in_cache' => $itemsCount
                ]);
                $shouldQueue = true;
                // Items count неизвестен, но мы идем в очередь, там разберемся
            } else {
                // Файл маленький, можно перепарсить синхронно
                Log::warning('[EstimateImport] Preview cache missing or empty in execute, regenerating sync', ['file_id' => $fileId]);
                try {
                    // FIX: Don't use matchingConfig as columnMapping, use cached mapping or auto-detect (null)
                    $columnMapping = Cache::get("estimate_import_mapping:{$fileId}");
                    $importDTO = $this->preview($fileId, $columnMapping);
                    $previewData = $importDTO->toArray();
                    $itemsCount = count($previewData['items'] ?? []);
                } catch (\Exception $e) {
                    Log::error('[EstimateImport] Failed to regenerate preview in execute', ['error' => $e->getMessage()]);
                    throw $e;
                }
            }
        }

        // Apply detected VAT rate if not explicitly set in settings
        if (!isset($estimateSettings['vat_rate']) && isset($previewData['vat_rate'])) {
            $estimateSettings['vat_rate'] = $previewData['vat_rate'];
            Log::info('[EstimateImport] Using detected VAT rate', ['rate' => $estimateSettings['vat_rate']]);
        }

        $jobId = Str::uuid()->toString();
        
        // Логика выбора: если явно forced queue или количество элементов > 500
        $shouldQueue = $shouldQueue || ($itemsCount > 500);

        Log::info('[EstimateImport] 🚀 Начало импорта (Streaming)', [
            'file_id' => $fileId,
            'items_count_estimate' => $itemsCount,
            'job_id' => $jobId,
            'import_type' => $shouldQueue ? 'async' : 'sync',
            'validate_only' => $validateOnly,
        ]);
        
        // Объединяем разделы и позиции для передачи в процессор, если данные берутся из кэша (превью)
        // ВАЖНО: Для Excel-смет (где важен порядок строк) лучше НЕ использовать прелоад, 
        // а стримить заново, чтобы сохранить иерархию.
        $preloadedData = null;
        $extension = strtolower(pathinfo($fileData['file_path'], PATHINFO_EXTENSION));
        
        if (!empty($previewData) && !in_array($extension, ['xlsx', 'xls'])) {
            $sections = $previewData['sections'] ?? [];
            $items = $previewData['items'] ?? [];
            $preloadedData = array_merge($sections, $items);
        }
        
        // Dry Run всегда синхронно (пока)
        if ($validateOnly) {
            return $this->syncImport($fileId, $matchingConfig, $estimateSettings, $jobId, true, $preloadedData);
        }
        
        if (!$shouldQueue) {
            return $this->syncImport($fileId, $matchingConfig, $estimateSettings, $jobId, false, $preloadedData);
        } else {
            return $this->queueImport($fileId, $matchingConfig, $estimateSettings);
        }
    }

    public function syncImport(string $fileId, array $matchingConfig, array $estimateSettings, ?string $jobId = null, bool $validateOnly = false, ?array $preloadedItems = null): array
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
                'result_log' => $validateOnly ? ['mode' => 'dry_run'] : [],
            ]);
        }
        
        try {
            // Используем новый потоковый метод
            $result = $this->createEstimateFromStream($fileId, $matchingConfig, $estimateSettings, $progressTracker, $jobId, $preloadedItems, $validateOnly);
            
            $processingTime = (microtime(true) - $startTime) * 1000;
            $result->processingTimeMs = (int)$processingTime;
            
            if ($jobId) {
                EstimateImportHistory::where('job_id', $jobId)->update([
                    'status' => 'completed',
                    'estimate_id' => $result->estimateId, // Может быть null если validateOnly
                    'items_imported' => $result->itemsImported,
                    'result_log' => $result->toArray(),
                    'processing_time_ms' => $result->processingTimeMs,
                    'progress' => 100,
                ]);
            }
            
            if (!$validateOnly) {
                $this->cleanup($fileId);
            }
            
            return [
                'status' => 'completed',
                'job_id' => $jobId,
                'estimate_id' => $result->estimateId,
                'result' => $result->toArray(),
                'validate_only' => $validateOnly,
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
        );
        
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
        
        $progress = $history->progress;

        // If processing, try to get real-time progress from cache (bypassing transaction isolation)
        if ($history->status === 'processing') {
            $cachedProgress = Cache::get("import_progress_{$jobId}");
            if ($cachedProgress !== null) {
                $progress = (int)$cachedProgress;
            }
        }
        
        return [
            'status' => $history->status,
            'progress' => $progress,
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
            
            // ✅ Включаем вычисление формул
            \PhpOffice\PhpSpreadsheet\Calculation\Calculation::getInstance($spreadsheet)->disableBranchPruning();
            
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
                    $cell = $sheet->getCell($col . $currentRow);
                    
                    // ✅ Вычисляем формулы
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

    private function columnIndexFromString(?string $columnLetter): ?int
    {
        if (empty($columnLetter)) {
            return null;
        }
        
        $columnLetter = strtoupper($columnLetter);
        $length = strlen($columnLetter);
        $index = 0;
        
        for ($i = 0; $i < $length; $i++) {
            $index *= 26;
            $index += ord($columnLetter[$i]) - 64;
        }
        
        return $index - 1; // 0-based index
    }

    private function getParser(string $filePath): EstimateImportParserInterface
    {
        // Используем старый фабричный метод для совместимости в методах, где нужен EstimateImportParserInterface
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        // Check if it's a Prohelper format (for Excel files)
        if ($extension === 'xlsx' || $extension === 'xls') {
            $prohelperParser = new ProhelperEstimateParser();
            if ($prohelperParser->canParse($filePath)) {
                Log::info('estimate_import.prohelper_format_detected', ['file' => $filePath]);
                return $prohelperParser;
            }
        }
        
        return match ($extension) {
            'xml' => new UniversalXmlParser(),
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
        ?array $preloadedItems = null,
        bool $validateOnly = false
    ): EstimateImportResultDTO {
        DB::beginTransaction();
        
        try {
            $progressTracker->update(10, 100, 0, 10);
            
            // Если validateOnly, мы все равно создаем смету, но потом откатим транзакцию
            // Это нужно, чтобы работали внешние ключи (section.estimate_id и т.д.)
            $estimate = $this->estimateService->create([
                'name' => $settings['name'] . ($validateOnly ? ' [DRY RUN]' : ''),
                'type' => $settings['type'],
                'project_id' => $settings['project_id'] ?? null,
                'contract_id' => $settings['contract_id'] ?? null,
                'organization_id' => $settings['organization_id'],
                'status' => 'draft',
                'estimate_date' => now()->toDateString(),
                'vat_rate' => $settings['vat_rate'] ?? null, // Pass VAT rate
            ]);
            
            $progressTracker->update(25, 100, 0, 25);
            
            $parser = null;
            $fileData = null;

            if ($fileId) {
                $fileData = $this->getFileData($fileId);
                $parser = $this->parserFactory->getParser($fileData['file_path']);
            }

            // Если items переданы (из превью), используем их. Иначе стримим из файла.
            if ($preloadedItems) {
                $iterator = $preloadedItems;
            } else {
                if (!$fileData || !$parser) {
                    throw new \RuntimeException('File data or parser not available for streaming import');
                }

                if ($parser instanceof ExcelStreamParser) {
                    // Для стриминга используем генератор напрямую
                    $rawIterator = $parser->parse($fileData['file_path']);
                    
                    // Обертка для маппинга "на лету"
                    // Нам нужно передать mapping из конфига
                    $columnMapping = $matchingConfig ?? [];
                    
                    $iterator = (function() use ($rawIterator, $columnMapping) {
                        foreach ($rawIterator as $row) {
                            // Преобразуем индексированный массив (строку excel) в именованный массив через маппинг
                            $mappedRow = [];
                            foreach ($columnMapping as $field => $columnIndex) {
                                // 🔧 Исправление: $columnIndex может быть массивом ['column' => 'A', ...]
                                $columnLetter = is_array($columnIndex) ? ($columnIndex['column'] ?? null) : $columnIndex;
                                
                                $idx = $this->columnIndexFromString($columnLetter);
                                if ($idx !== null && isset($row[$idx])) {
                                    $val = $row[$idx];
                                    
                                    // 🔧 Очистка числовых значений
                                    if (in_array($field, ['quantity', 'unit_price', 'current_total_amount'])) {
                                        $val = $this->cleanNumericValue($val);
                                    }
                                    
                                    // 🔧 Очистка названий
                                    if ($field === 'item_name' || $field === 'name') {
                                        $val = $this->cleanItemName($val);
                                    }

                                    $mappedRow[$field] = $val;
                                }
                            }
                            
                            // Пропускаем пустые строки
                            if (empty($mappedRow) || (empty($mappedRow['item_name']) && empty($mappedRow['code']))) {
                                continue;
                            }
                            
                            yield $mappedRow;
                        }
                    })();
                    
                } elseif ($parser instanceof UniversalXmlParser) {
                    // Для XML используем потоковый парсинг через генератор
                    $iterator = $parser->parse($fileData['file_path']);
                    Log::info('[EstimateImport] Using UniversalXmlParser stream iterator');
                } else {
                    // Fallback для других парсеров (XML, CSV) - пока через память или их собственные методы
                    // Временное решение: используем превью данные
                    $previewData = Cache::get("estimate_import_preview:{$fileId}");
                    
                    if (empty($previewData['items'])) {
                        Log::info('[EstimateImport] Preview cache missing/empty in stream creation, regenerating', ['file_id' => $fileId]);
                        
                        // FIX: Don't use matchingConfig as columnMapping
                        $columnMapping = Cache::get("estimate_import_mapping:{$fileId}");
                        // Если маппинга нет, передаем null, чтобы сработал автодетект
                        $importDTO = $this->preview($fileId, $columnMapping);
                        $previewData = $importDTO->toArray();
                    }

                    // FIX: Merge sections and items so Processor creates sections first
                    $sections = $previewData['sections'] ?? [];
                    $items = $previewData['items'] ?? [];
                    $iterator = array_merge($sections, $items);
                    
                    Log::info('[EstimateImport] Stream iterator prepared', [
                        'count' => is_array($iterator) ? count($iterator) : 'unknown (generator)',
                        'sections_count' => count($sections),
                        'items_count' => count($items),
                        'first_item' => is_array($iterator) && !empty($iterator) ? array_keys($iterator[0]) : null
                    ]); 
                }
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

            // CHECK FOR DISCREPANCIES AND LOG DIAGNOSTICS (No auto-correction)
            if ($parser instanceof UniversalXmlParser && !empty($fileData['file_path'])) {
                $summaryTotals = $parser->extractSummaryTotals($fileData['file_path']);
                
                if (!empty($summaryTotals) && isset($summaryTotals['total_direct_costs'])) {
                    $targetDirect = (float)$summaryTotals['total_direct_costs'];
                    
                    // Get calculated total direct costs
                    $estimate->refresh();
                    $currentDirect = (float)$estimate->total_direct_costs;
                    $diff = round($currentDirect - $targetDirect, 2);
                    
                    // Log discrepancy to estimate diagnostics
                    $diagnostics = $estimate->import_diagnostics ?? [];
                    $diagnostics['xml_totals_comparison'] = [
                        'target_direct_costs' => $targetDirect,
                        'calculated_direct_costs' => $currentDirect,
                        'difference' => $diff,
                        'is_match' => abs($diff) <= 0.01,
                        'checked_at' => now()->toIso8601String(),
                        'summary_totals_source' => $summaryTotals
                    ];
                    
                    if (abs($diff) > 0.01) {
                        Log::warning("[EstimateImport] Discrepancy detected: Calculated=$currentDirect, XML=$targetDirect, Diff=$diff");
                    }
                    
                    $estimate->import_diagnostics = $diagnostics;
                    $estimate->save();
                }
            }

            // Если в XML есть итоговые суммы, используем их как первоисточник
            /* DISABLED: Testing if calculation matches XML exactly now that items are fixed
            if ($parser instanceof UniversalXmlParser && !empty($fileData['file_path'])) {
                $summaryTotals = $parser->extractSummaryTotals($fileData['file_path']);
                if (!empty($summaryTotals)) {
                    $totalAmount = round((float)$summaryTotals['total_amount'], 2);
                    $vatRate = (float)($estimate->vat_rate ?? 0);
                    $totalAmountWithVat = $totalAmount * (1 + $vatRate / 100);

                    $estimate->update([
                        'total_direct_costs' => round((float)$summaryTotals['total_direct_costs'], 2),
                        'total_overhead_costs' => round((float)$summaryTotals['total_overhead_costs'], 2),
                        'total_estimated_profit' => round((float)$summaryTotals['total_estimated_profit'], 2),
                        'total_amount' => $totalAmount,
                        'total_amount_with_vat' => round($totalAmountWithVat, 2),
                    ]);
                }
            }
            */
            
            $progressTracker->update(95, 100, 0, 95);
            
            if ($validateOnly) {
                // В режиме валидации откатываем транзакцию, чтобы ничего не сохранилось
                DB::rollBack();
                
                // Возвращаем результат с пометкой валидации
                return new EstimateImportResultDTO(
                    estimateId: null, // Сметы нет
                    itemsTotal: is_array($iterator) ? count($iterator) : 0,
                    itemsImported: $itemsResult['imported'],
                    itemsSkipped: $itemsResult['skipped'],
                    sectionsCreated: $itemsResult['sections_created'] ?? 0,
                    newWorkTypesCreated: [], 
                    warnings: $itemsResult['warnings'] ?? [], 
                    errors: [],
                    status: 'validated'
                );
            }
            
            DB::commit();
            
            return new EstimateImportResultDTO(
                estimateId: $estimate->id,
                itemsTotal: is_array($iterator) ? count($iterator) : 0,
                itemsImported: $itemsResult['imported'],
                itemsSkipped: $itemsResult['skipped'],
                sectionsCreated: $itemsResult['sections_created'] ?? 0, 
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
    private function cleanNumericValue($value)
    {
        if ($value === null || $value === '' || is_numeric($value)) {
            return $value;
        }

        $str = (string)$value;

        // 1. Если multiline - берем только первую строку
        if (str_contains($str, "\n")) {
            $lines = explode("\n", $str);
            $str = trim($lines[0]);
        }
        
        // 2. Если содержит слеш / - берем первую часть
        if (str_contains($str, '/')) {
            $parts = explode('/', $str);
            $str = trim($parts[0]);
        }
        
        // 3. Очистка от мусора
        $cleaned = preg_replace('/[^\d.,\-]/', '', $str);
        $cleaned = str_replace(',', '.', $cleaned);
        
        return is_numeric($cleaned) ? (float)$cleaned : $value;
    }

    private function cleanItemName($value): string
    {
        if (!$value) return '';
        
        $val = (string)$value;
        
        $pruningPatterns = [
            '/ИНДЕКС К ПОЗИЦИИ/ui',
            '/НР\s*\(/ui',
            '/СП\s*\(/ui',
            '/ПЗ\s*=/ui',
            '/ЭМ\s*=/ui',
            '/ЗПм\s*=/ui',
            '/ОТм\s*=/ui',
            '/МАТ\s*=/ui',
        ];

        foreach ($pruningPatterns as $pattern) {
            if (preg_match($pattern, $val, $matches, PREG_OFFSET_CAPTURE)) {
                $val = trim(mb_substr($val, 0, $matches[0][1]));
            }
        }
        
        return $val;
    }
}
