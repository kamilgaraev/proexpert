<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\BusinessModules\Features\BudgetEstimates\Contracts\EstimateImportParserInterface;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportResultDTO;
use App\BusinessModules\Features\BudgetEstimates\DTOs\ImportMatchResultDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateSectionService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateItemService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\ExcelSimpleTableParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\NormativeMatchingService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\NormativeCodeService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ResourceMatchingService;
use App\Models\Estimate;
use App\Models\EstimateImportHistory;
use App\Models\WorkType;
use App\Models\MeasurementUnit;
use App\Jobs\ProcessEstimateImportJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

class EstimateImportService
{
    public function __construct(
        private EstimateService $estimateService,
        private EstimateSectionService $sectionService,
        private EstimateItemService $itemService,
        private EstimateCalculationService $calculationService,
        private ImportMappingService $mappingService,
        private ImportValidationService $validationService,
        private NormativeMatchingService $normativeMatchingService,
        private NormativeCodeService $codeService,
        private ResourceMatchingService $resourceMatchingService
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

    public function detectFormat(string $fileId, ?int $suggestedHeaderRow = null): array
    {
        Log::info('[EstimateImport] detectFormat started', [
            'file_id' => $fileId,
            'suggested_header_row' => $suggestedHeaderRow,
        ]);
        
        try {
            $fileData = $this->getFileData($fileId);
            Log::info('[EstimateImport] File data retrieved', [
                'file_path' => $fileData['file_path'],
                'file_exists' => file_exists($fileData['file_path']),
            ]);
        } catch (\Exception $e) {
            Log::error('[EstimateImport] Failed to get file data', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
        
        $parser = $this->getParser($fileData['file_path']);
        Log::info('[EstimateImport] Parser created', ['parser' => get_class($parser)]);
        
        // Используем предложенную пользователем строку или автоматическое определение
        if ($suggestedHeaderRow !== null) {
            Log::info('[EstimateImport] Using suggested header row', ['row' => $suggestedHeaderRow]);
            $structure = $parser->detectStructureFromRow($fileData['file_path'], $suggestedHeaderRow);
        } else {
            $structure = $parser->detectStructure($fileData['file_path']);
        }
        
        Log::info('[EstimateImport] Structure detected', [
            'columns_count' => count($structure['detected_columns'] ?? []),
            'header_row' => $structure['header_row'] ?? null,
        ]);
        
        // Получаем кандидатов на роль заголовков
        $headerCandidates = $parser->getHeaderCandidates();
        
        Cache::put("estimate_import_structure:{$fileId}", $structure, now()->addHours(24));
        
        return [
            'format' => 'excel_simple',
            'detected_columns' => $structure['detected_columns'],
            'raw_headers' => $structure['raw_headers'],
            'header_row' => $structure['header_row'],
            'header_candidates' => $headerCandidates, // Для UI выбора
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
            Log::debug('[EstimateImport] Column mapping saved to cache', [
                'file_id' => $fileId,
                'mapping' => $columnMapping,
            ]);
        }
        
        Log::debug('[EstimateImport] Parsing file', ['file_path' => $fileData['file_path']]);
        $importDTO = $parser->parse($fileData['file_path']);
        
        $previewArray = $importDTO->toArray();
        Cache::put("estimate_import_preview:{$fileId}", $previewArray, now()->addHours(24));
        
        Log::info('[EstimateImport] Preview completed and cached', [
            'file_id' => $fileId,
            'items_count' => count($previewArray['items'] ?? []),
            'sections_count' => count($previewArray['sections'] ?? []),
        ]);
        
        return $importDTO;
    }

    public function analyzeMatches(string $fileId, int $organizationId): array
    {
        $previewData = Cache::get("estimate_import_preview:{$fileId}");
        
        if ($previewData === null) {
            Log::info('[EstimateImport] Preview data not found in cache, generating automatically', [
                'file_id' => $fileId,
            ]);
            
            $columnMapping = Cache::get("estimate_import_mapping:{$fileId}");
            $importDTO = $this->preview($fileId, $columnMapping);
            $previewData = $importDTO->toArray();
        }
        
        $items = $previewData['items'];
        $matchResults = [];
        $workItems = array_filter($items, fn($item) => ($item['item_type'] ?? 'work') === 'work');
        
        // НОВАЯ СТАТИСТИКА с учетом кодов
        $summary = [
            // Поиск по кодам (новое)
            'code_exact_matches' => 0,
            'code_fuzzy_matches' => 0,
            'code_not_found' => 0,
            'items_with_codes' => 0,
            'items_without_codes' => 0,
            
            // Старая статистика (fallback по названиям)
            'name_exact_matches' => 0,
            'name_fuzzy_matches' => 0,
            'new_work_types_needed' => 0,
            
            // Общая статистика
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
            
            // ПРИОРИТЕТ 1: Поиск по коду норматива (ТОЛЬКО для работ!)
            if (!empty($code)) {
                $summary['items_with_codes']++;
                
                $normativeMatch = $this->normativeMatchingService->findByCode($code);
                
                if ($normativeMatch) {
                    // Найден норматив по коду
                    if ($normativeMatch['confidence'] === 100) {
                        $summary['code_exact_matches']++;
            } else {
                        $summary['code_fuzzy_matches']++;
                    }
                    
                    $matchResult = [
                        'imported_text' => $importedText,
                        'code' => $code,
                        'match_type' => 'code',
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
                    // Код не найден в справочнике
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
                // Код отсутствует - fallback поиск по названию (TODO: реализовать)
                $summary['items_without_codes']++;
                
                // TODO: реализовать NormativeMatchingService::findByName()
                $matchResult = [
                    'imported_text' => $importedText,
                    'code' => null,
                    'match_type' => 'name_not_found',
                    'matched_normative' => null,
                    'confidence' => 0,
                    'method' => 'name_search_not_implemented',
                    'should_create' => true,
                    'warning' => 'Код отсутствует, поиск по названию пока не реализован',
                ];
                $summary['new_work_types_needed']++;
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
        
        // ⭐ Генерируем job_id для отслеживания статуса
        $jobId = Str::uuid()->toString();
        
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
        
        // ⭐ Создаем запись в истории ДО начала импорта (для updateProgress)
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
            $result = $this->createEstimateFromImport($importDTO, $matchingConfig, $estimateSettings, $jobId);
            
            $processingTime = (microtime(true) - $startTime) * 1000;
            $result->processingTimeMs = (int)$processingTime;
            
            // ⭐ Обновляем запись (а не создаем новую)
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
            // ⭐ Обновляем запись при ошибке
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
        
        $history = EstimateImportHistory::create([
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
        
        // ⭐ Получаем project_id из настроек для правильного URL
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
        $history = EstimateImportHistory::where('job_id', $jobId)->firstOrFail();
        
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
        ?string $jobId = null
    ): EstimateImportResultDTO {
        DB::beginTransaction();
        
        try {
            $this->updateProgress($jobId, 10);
            
            $estimate = $this->estimateService->create([
                'name' => $settings['name'],
                'type' => $settings['type'],
                'project_id' => $settings['project_id'] ?? null,
                'contract_id' => $settings['contract_id'] ?? null,
                'organization_id' => $settings['organization_id'],
                'status' => 'draft',
                'estimate_date' => now()->toDateString(),
            ]);
            
            $this->updateProgress($jobId, 25);
            
            $newWorkTypes = [];
            if ($matchingConfig['create_new_work_types'] ?? false) {
                $newWorkTypes = $this->createMissingWorkTypes($importDTO, $settings['organization_id']);
            }
            
            $this->updateProgress($jobId, 40);
            
            $sectionsMap = $this->createSections($estimate, $importDTO->sections);
            
            $this->updateProgress($jobId, 50);
            
            $itemsResult = $this->createItems(
                $estimate,
                $importDTO->items,
                $sectionsMap,
                $matchingConfig,
                $newWorkTypes,
                $settings['organization_id']
            );
            
            $this->updateProgress($jobId, 85);
            
            $this->calculationService->recalculateAll($estimate);
            
            $this->updateProgress($jobId, 95);
            
            DB::commit();
            
            return new EstimateImportResultDTO(
                estimateId: $estimate->id,
                itemsTotal: count($importDTO->items),
                itemsImported: $itemsResult['imported'],
                itemsSkipped: $itemsResult['skipped'],
                sectionsCreated: count($sectionsMap),
                newWorkTypesCreated: array_map(fn($wt) => [
                    'id' => $wt->id,
                    'name' => $wt->name,
                ], $newWorkTypes),
                warnings: $this->validationService->generateWarnings($importDTO),
                errors: [],
                status: 'completed'
            );
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function createMissingWorkTypes(EstimateImportDTO $importDTO, int $organizationId): array
    {
        // Теперь не создаем WorkType - вместо этого создаем позиции с кодом/названием напрямую
        // Нормативы ищутся в справочнике, если не найдены - сохраняем как есть
        return [];
    }

    private function createSections(Estimate $estimate, array $sections): array
    {
        $sectionsMap = [];
        
        Log::info('[EstimateImport] 📁 Создание разделов', [
            'total_sections' => count($sections),
        ]);
        
        foreach ($sections as $index => $sectionData) {
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
            
            // 🔍 ЛОГИРУЕМ ПЕРВЫЕ 10 РАЗДЕЛОВ
            if ($index < 10) {
                Log::info("[EstimateImport] 📁 Раздел #{$index}", [
                    'section_number' => $sectionData['section_number'],
                    'name' => substr($sectionData['item_name'] ?? '', 0, 100),
                    'level' => $sectionData['level'] ?? 0,
                    'parent_id' => $parentId,
                    'section_id' => $section->id,
                    'map_key' => $sectionData['section_number'],
                ]);
            }
        }
        
        Log::info('[EstimateImport] ✅ Разделы созданы', [
            'total_created' => count($sectionsMap),
            'map_keys' => array_keys($sectionsMap),
        ]);
        
        return $sectionsMap;
    }

    private function createItems(
        Estimate $estimate,
        array $items,
        array $sectionsMap,
        array $matchingConfig,
        array $newWorkTypes,
        int $organizationId
    ): array {
        $imported = 0;
        $skipped = 0;
        $codeMatches = 0;
        $nameMatches = 0;
        
        // ⭐ Отслеживание текущей работы ГЭСН для связывания подпозиций
        $currentWorkId = null;
        
        // 🔍 СТАТИСТИКА ПО ТИПАМ ДЛЯ ЛОГИРОВАНИЯ
        $typeStats = [
            'work' => 0,
            'material' => 0,
            'equipment' => 0,
            'machinery' => 0,
            'labor' => 0,
            'summary' => 0,
        ];
        
        $totalItems = count($items);
        
        Log::info('[EstimateImport] ⏳ Начало импорта позиций', [
            'total_items' => $totalItems,
            'organization_id' => $organizationId,
        ]);
        
        foreach ($items as $index => $item) {
            try {
                // 🔍 ЛОГИРУЕМ КАЖДУЮ 50-Ю ПОЗИЦИЮ
                if ($index > 0 && $index % 50 === 0) {
                    Log::info("[EstimateImport] ⏳ Прогресс: {$index}/{$totalItems}", [
                        'imported' => $imported,
                        'skipped' => $skipped,
                        'types' => $typeStats,
                    ]);
                }
                
                $sectionId = null;
                $sectionPath = $item['section_path'] ?? null;
                
                // ⭐ ПОПЫТКА НАЙТИ РАЗДЕЛ ПО section_path
                if (!empty($sectionPath) && isset($sectionsMap[$sectionPath])) {
                    $sectionId = $sectionsMap[$sectionPath];
                }
                
                $itemType = $item['item_type'] ?? 'work';
                $typeStats[$itemType] = ($typeStats[$itemType] ?? 0) + 1;
                
                // 🔍 ЛОГИРУЕМ ПЕРВЫЕ 10 ПОЗИЦИЙ С ПРИВЯЗКОЙ К РАЗДЕЛАМ
                if ($index < 10) {
                    Log::info("[EstimateImport] 🔍 Позиция #{$index}", [
                        'type' => $itemType,
                        'name' => substr($item['item_name'] ?? '', 0, 100),
                        'code' => $item['code'] ?? null,
                        'section_path' => $sectionPath,
                        'section_id' => $sectionId,
                        'section_found' => $sectionId !== null,
                        'unit' => $item['unit'] ?? null,
                        'quantity' => $item['quantity'] ?? null,
                        'unit_price' => $item['unit_price'] ?? null,
                    ]);
                }
                
                // ⭐ Fallback: если unit_price = null, используем current_unit_price
                $unitPrice = $item['unit_price'] ?? $item['current_unit_price'] ?? 0;
                
                $itemData = [
                    'estimate_id' => $estimate->id,
                    'estimate_section_id' => $sectionId,
                    'parent_work_id' => null, // ⭐ Будет установлен для подпозиций
                    'item_type' => $itemType,
                    'name' => $item['item_name'],
                    'unit' => $item['unit'],
                    'quantity' => $item['quantity'],
                    'quantity_coefficient' => $item['quantity_coefficient'] ?? null,
                    'quantity_total' => $item['quantity_total'] ?? null,
                    'unit_price' => $unitPrice, // ⭐ С fallback на current_unit_price
                    'base_unit_price' => $item['base_unit_price'] ?? null,
                    'price_index' => $item['price_index'] ?? null,
                    'current_unit_price' => $item['current_unit_price'] ?? $unitPrice, // ⭐ Обратный fallback
                    'price_coefficient' => $item['price_coefficient'] ?? null,
                    'current_total_amount' => $item['current_total_amount'] ?? null,
                    'code' => $item['code'] ?? null,
                    'is_not_accounted' => $item['is_not_accounted'] ?? false, // ⭐ Флаг "Н"
                ];
                
                // ⭐ ЛОГИКА ИМПОРТА С УЧЕТОМ ТИПА ПОЗИЦИИ
                
                // ⭐ ВСЕ РЕСУРСЫ (материалы, механизмы, трудозатраты): ищем/создаем в справочниках
                if (in_array($itemType, ['material', 'equipment', 'machinery', 'labor'])) {
                    // ⭐ Устанавливаем связь с родительской работой ГЭСН
                    if ($currentWorkId) {
                        $itemData['parent_work_id'] = $currentWorkId;
                    }
                    
                    if (!empty($item['code'])) {
                        try {
                            // Универсальный поиск/создание ресурса
                            $result = $this->resourceMatchingService->findOrCreate(
                                $itemType === 'equipment' ? 'material' : $itemType, // equipment хранится как material
                                $item['code'],
                                $item['item_name'],
                                $item['unit'],
                                $item['unit_price'] ?? null,
                                $organizationId,
                                [
                                    'item_type' => $itemType,
                                    'is_not_accounted' => $item['is_not_accounted'] ?? false, // ⭐ Передаем флаг "Н"
                                ]
                            );
                            
                            // Связываем с позицией сметы
                            match ($result['type']) {
                                'material' => $itemData['material_id'] = $result['resource']->id,
                                'machinery' => $itemData['machinery_id'] = $result['resource']->id,
                                'labor' => $itemData['labor_resource_id'] = $result['resource']->id,
                                default => null,
                            };
                            
                            Log::info('estimate_import.resource_linked', [
                                'type' => $itemType,
                                'resource_type' => $result['type'],
                                'code' => $item['code'],
                                'resource_id' => $result['resource']->id,
                                'name' => $result['resource']->name,
                                'created' => $result['created'],
                                'parent_work_id' => $currentWorkId,
                                'is_not_accounted' => $item['is_not_accounted'] ?? false,
                            ]);
                        } catch (\Exception $e) {
                            Log::error('estimate_import.resource_failed', [
                                'type' => $itemType,
                                'code' => $item['code'],
                                'name' => $item['item_name'],
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                    
                    $this->itemService->addItem($itemData, $estimate);
                    $imported++;
                    
                    continue;
                }
                
                // ⭐ ИТОГИ: импортируем как есть
                if ($itemType === 'summary') {
                    $this->itemService->addItem($itemData, $estimate);
                    $imported++;
                    
                    continue;
                }
                
                // ⭐ ТОЛЬКО ДЛЯ РАБОТ: ищем в справочнике нормативов
                $normativeFound = false;
                
                // Приоритет 1: Поиск по коду норматива (если есть код)
                if (!empty($item['code'])) {
                    $normativeMatch = $this->normativeMatchingService->findByCode($item['code']);
                    
                    if ($normativeMatch) {
                        // Найден норматив по коду - автоподстановка данных
                        $itemData = $this->normativeMatchingService->fillFromNormative(
                            $normativeMatch['normative'],
                            $itemData
                        );
                        
                        $normativeFound = true;
                        $codeMatches++;
                        
                        Log::info('normative.code_match', [
                            'code' => $item['code'],
                            'normative_id' => $normativeMatch['normative']->id,
                            'confidence' => $normativeMatch['confidence'],
                            'method' => $normativeMatch['method'],
                        ]);
                    } else {
                        Log::warning('normative.work_code_not_found', [
                            'code' => $item['code'],
                            'name' => $item['item_name'],
                        ]);
                    }
                }
                
                // Приоритет 2: Fallback - поиск по названию (если по коду не найден)
                if (!$normativeFound && !empty($item['item_name'])) {
                    // TODO: реализовать поиск по названию в NormativeMatchingService
                    // Пока просто импортируем как есть
                    
                    Log::debug('normative.name_search_skipped', [
                        'name' => $item['item_name'],
                        'reason' => 'Name search not implemented yet',
                    ]);
                }
                
                // Импортируем позицию работы (с нормативом или без)
                $createdItem = $this->itemService->addItem($itemData, $estimate);
                $imported++;
                
                // ⭐ Обновляем текущую работу ГЭСН для связывания последующих подпозиций
                $currentWorkId = $createdItem->id;
                
            } catch (\Exception $e) {
                Log::error('estimate_import.create_item.failed', [
                    'item' => $item,
                    'error' => $e->getMessage(),
                ]);
                $skipped++;
            }
        }
        
        // 🔍 ФИНАЛЬНАЯ СТАТИСТИКА ИМПОРТА
        Log::info('[EstimateImport] ✅ Импорт завершен', [
            'total_items' => $totalItems,
            'imported' => $imported,
            'skipped' => $skipped,
            'code_matches' => $codeMatches,
            'name_matches' => $nameMatches,
            'types_breakdown' => $typeStats,
        ]);
        
        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'code_matches' => $codeMatches,
            'name_matches' => $nameMatches,
        ];
    }

    // Метод удален - теперь поиск идет только через NormativeMatchingService

    private function findOrCreateUnit(string $unitName, int $organizationId): MeasurementUnit
    {
        $normalized = mb_strtolower(trim($unitName));
        
        $unit = MeasurementUnit::where('organization_id', $organizationId)
            ->whereRaw('LOWER(name) = ?', [$normalized])
            ->first();
        
        if ($unit === null) {
            $shortName = mb_strlen($unitName) > 10 
                ? mb_substr($unitName, 0, 10) 
                : $unitName;
            
            $unit = MeasurementUnit::create([
                'organization_id' => $organizationId,
                'name' => $unitName,
                'short_name' => $shortName,
                'type' => 'work',
            ]);
        }
        
        return $unit;
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
            $maxRow = min($headerRow + 20, $sheet->getHighestRow()); // Первые 20 строк после заголовков
            
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
            Log::error('[EstimateImport] Failed to get sample rows', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function getParser(string $filePath): EstimateImportParserInterface
    {
        return new ExcelSimpleTableParser();
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

    private function updateProgress(?string $jobId, int $progress): void
    {
        if ($jobId === null) {
            return;
        }

        EstimateImportHistory::where('job_id', $jobId)
            ->update(['progress' => $progress]);
    }
}

