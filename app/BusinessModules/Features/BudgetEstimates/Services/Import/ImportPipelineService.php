<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\BusinessModules\Features\BudgetEstimates\Services\EstimateService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\Factory\ParserFactory;
use App\Models\Estimate;
use App\Models\EstimateSection;
use App\Models\EstimateItem;
use App\Models\ImportSession;
use App\Models\MeasurementUnit;
use App\Models\NormativeRate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Classification\ItemClassificationService;

class ImportPipelineService
{
    private const BATCH_SIZE = 100;
    private const DEFAULT_VAT_RATE = 20;

    private array $unitCache = [];

    public function __construct(
        private ParserFactory $parserFactory,
        private FileStorageService $fileStorage,
        private ImportRowMapper $rowMapper,
        private EstimateService $estimateService,
        private ItemClassificationService $classifier,
        private NormativeMatchingService $matcher,
        private SemanticMatchingService $semanticMatcher,
        private SubItemGroupingService $subItemGrouper,
        private FormulaAwarenessService $formulaAwareness,
        private \App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService $calculationService
    ) {}

    private function updateProgress(ImportSession $session, int $progress, string $message): void
    {
        // 1. Update Cache for real-time tracking (bypasses DB transactions)
        Cache::put("import_session_progress_{$session->id}", $progress, 3600);

        // 2. Update Database (will be committed later)
        $fresh = $session->fresh();
        $session->update([
            'stats' => array_merge($fresh->stats ?? [], [
                'progress' => $progress,
                'message'  => $message,
            ])
        ]);
    }

    public function run(ImportSession $session, array $config = []): void
    {
        Log::info("[ImportPipeline] Started for session {$session->id}");
        
        $session->update([
            'status' => 'parsing', 
            'stats' => array_merge($session->fresh()->stats ?? [], ['progress' => 5, 'message' => 'Starting import pipeline...'])
        ]);

        $filePath = $this->fileStorage->getAbsolutePath($session);
        
        $formatHandler = $session->options['format_handler'] ?? null;
        if ($formatHandler === 'grandsmeta') {
            $parser = app(\App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\GrandSmeta\GrandSmetaParser::class);
        } else {
            $parser = $this->parserFactory->getParser($filePath);
        }
        
        // Prepare Parser Options
        $structure = $session->options['structure'] ?? [];
        $options = [
            'header_row' => $structure['header_row'] ?? null,
            'column_mapping' => $structure['column_mapping'] ?? [],
        ];

        if (!empty($structure['ai_section_hints'])) {
             $this->rowMapper->setSectionHints($structure['ai_section_hints']);
        }

        if (!empty($structure['row_styles'])) {
             $this->rowMapper->setRowStyles($structure['row_styles']);
        }

        // 1. Оцениваем общее количество строк для прогресса
        $totalRows = method_exists($parser, 'getTotalRows') ? $parser->getTotalRows($filePath, $options) : 0;
        
        $session->update([
            'stats' => array_merge($session->fresh()->stats ?? [], [
                'progress'   => 10,
                'total_rows' => $totalRows,
                'message'    => $totalRows > 0 ? "File has {$totalRows} rows. Starting processing..." : 'Parsing file...'
            ])
        ]);

        // 2. Create or Get Estimate
        $estimate = $this->resolveEstimate($session);
        
        // 3. Stream & Process
        $options['raw_progress_callback'] = function (int $progress, string $message) use ($session) {
            $this->updateProgress($session, $progress, $message);
        };

        if ($formatHandler === 'grandsmeta') {
            $progressCallback = function (int $current, int $total) use ($session) {
                // Grandsmeta takes stream callback mapped to 10-88%
                $pct = (int) (10 + min(78, ($current / max(1, $total)) * 78));
                $this->updateProgress($session, $pct, "Processed {$current}/{$total} rows...");
            };
            $stream = $parser->getStream($filePath, $options, $progressCallback);
        } else {
            $stream = $parser->getStream($filePath, $options);
        }
        
        $stats = [
            'processed_rows' => 0,
            'sections_created' => 0,
            'items_created' => 0,
            'total_rows' => $totalRows,
        ];
        
        DB::beginTransaction();
        try {
            $this->processStream($stream, $estimate, $stats, $session);
            
            // Extract and save footer data if the parser supports it
            if (method_exists($parser, 'getFooterData')) {
                $footerData = $parser->getFooterData();
                if (!empty($footerData)) {
                    Log::info("[ImportPipeline] Found footer data for session {$session->id}", $footerData);
                    
                    // Update estimate with footer values
                    $meta = $estimate->metadata ?? [];
                    $meta['footer'] = $footerData;
                    
                    // ⭐ КАЛИБРОВКА СТАВОК (CALIBRATION)
                    // Если в подвале удалось найти ФОТ и НР/СП - доверяем им, 
                    // так как это единственный способ попасть в математику Гранд-Сметы.
                    $directCosts = (float)($footerData['direct_costs'] ?? 0);
                    $laborCostFromFooter = (float)($footerData['labor_cost'] ?? 0); // ФОТ
                    $overheadFromFooter = (float)($footerData['overhead_cost'] ?? 0);
                    $profitFromFooter = (float)($footerData['profit_cost'] ?? 0);
                    
                    $baseForCalibration = $laborCostFromFooter > 0 ? $laborCostFromFooter : ($directCosts > 0 ? $directCosts : 0);
                    
                    if ($baseForCalibration > 0) {
                        Log::info("[ImportPipeline] Calibrating rates using footer: base={$baseForCalibration}, OH={$overheadFromFooter}, P={$profitFromFooter}");
                        if ($overheadFromFooter > 0) {
                            $estimate->overhead_rate = round(($overheadFromFooter / $baseForCalibration) * 100, 2);
                        }
                        if ($profitFromFooter > 0) {
                            $estimate->profit_rate = round(($profitFromFooter / $baseForCalibration) * 100, 2);
                        }
                    }
                    
                    $estimate->metadata = $meta; 
                    $estimate->save();
                }
            }

            // Update Totals with proper markup distribution
            $this->updateProgress($session, 90, 'Recalculating totals...');
            $this->calculationService->recalculateAll($estimate);
            
            DB::commit();
            
            Log::info("[ImportPipeline] Finished for session {$session->id}", $stats);
            
            $session->update([
                'status' => 'completed',
                'stats'  => array_merge($session->fresh()->stats ?? [], [
                    'progress'    => 100,
                    'result'      => $stats,
                    'estimate_id' => $estimate->id,
                    'message'     => 'Import successfully completed.',
                ])
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("[ImportPipeline] Error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            throw $e;
        }
    }

    private function resolveEstimate(ImportSession $session): Estimate
    {
        $settings = $session->options['estimate_settings'] ?? [];
        
        return $this->estimateService->create([
            'organization_id' => $session->organization_id,
            'project_id' => $settings['project_id'] ?? null,
            'name' => $settings['name'] ?? $session->file_name,
            'type' => $settings['type'] ?? 'local',
            'estimate_date' => $settings['estimate_date'] ?? now()->format('Y-m-d'),
            'contract_id' => $settings['contract_id'] ?? null,
            'vat_rate' => $settings['vat_rate'] ?? self::DEFAULT_VAT_RATE,
            'overhead_rate' => 0,
            'profit_rate' => 0,
        ]);
    }

    private array $subItemState = [];

    private function processStream(\Generator $stream, Estimate $estimate, array &$stats, ImportSession $session): void
    {
        $batchDTOs = [];
        $sectionMap = []; // path -> section_id (e.g. "1" -> 101, "1.1" -> 102)
        $lastSectionId = null; // For items without explicit section path?
        
        foreach ($stream as $rowDTO) {
            // Skip technical rows
            if ($this->rowMapper->isTechnicalRow($rowDTO->rawData)) {
                continue;
            }

            // Apply mapping ONLY if not using specialized handler like GrandSmeta
            // Specialized handlers return already mapped DTOs.
            $handler = $session->options['format_handler'] ?? 'generic';
            if ($handler !== 'grandsmeta') {
                $rowDTO = $this->rowMapper->map($rowDTO, $session->options['structure']['column_mapping'] ?? []);
            }
            
            // Skip rows that are identified as footers (totals, summaries, etc.)
            if ($rowDTO->isFooter) {
                continue;
            }

            // Skip rows that have no numeric value (Quantity=0 AND Price=0 AND Total=0)
            // This filters out headers that were technically mapped but contain no data.
            if (!$rowDTO->isSection && 
                ($rowDTO->quantity === null || $rowDTO->quantity == 0) && 
                ($rowDTO->unitPrice === null || $rowDTO->unitPrice == 0) &&
                ($rowDTO->currentTotalAmount === null || $rowDTO->currentTotalAmount == 0)
            ) {
                Log::info("[ImportPipeline] Skipping empty/garbage item: '{$rowDTO->itemName}'");
                continue;
            }

            if ($rowDTO->isSection) {
                // If we have items in batch, process and save them first to maintain order
                if (!empty($batchDTOs)) {
                    $this->processAndInsertBatch($batchDTOs, $estimate, $stats, $session);
                    $batchDTOs = [];
                }
                
                // Сбрасываем подпункты, так как начался новый раздел
                $this->subItemState = [];

                // Save Section immediately (needed for ID)
                $section = $this->saveSection($rowDTO, $estimate->id, $sectionMap);
                $lastSectionId = $section->id;
                $stats['sections_created']++;
            } else {
                // Collect row but assign closest section
                $rowDTO->sectionPath = $rowDTO->sectionPath ?: null; 
                $batchDTOs[] = [
                    'dto' => $rowDTO,
                    'section_id' => $this->resolveSectionId($rowDTO, $sectionMap, $lastSectionId)
                ];
                
                if (count($batchDTOs) >= self::BATCH_SIZE) {
                    $this->processAndInsertBatch($batchDTOs, $estimate, $stats, $session);
                    $batchDTOs = [];
                }
            }

            $stats['processed_rows']++;
            
            // Обновляем прогресс каждые 10 строк
            if ($stats['processed_rows'] % 10 === 0) {
                $totalRows = $stats['total_rows'] ?? 0;
                // Распределяем диапазон от 10% до 88%
                $progress = $totalRows > 0
                    ? (int) (10 + min(78, (($stats['processed_rows'] / $totalRows) * 78)))
                    : min(88, 10 + (int) ($stats['processed_rows'] / 5));

                $this->updateProgress(
                    $session,
                    $progress,
                    "Processed {$stats['processed_rows']}" . ($totalRows > 0 ? "/{$totalRows}" : '') . ' rows...'
                );
                $session->stats = array_merge($session->fresh()->stats ?? [], ['processed_rows' => $stats['processed_rows']]);
            }
        }
        
        // Final batch
        if (!empty($batchDTOs)) {
            $this->processAndInsertBatch($batchDTOs, $estimate, $stats, $session);
        }
    }

    private function processAndInsertBatch(array $batch, Estimate $estimate, array &$stats, ImportSession $session): void
    {
        $itemsToInsert = [];
        $aiBatch = [];

        // Step 1: Normative Matching
        foreach ($batch as $index => $item) {
            $dto = $item['dto'];
            $itemData = $this->prepareWorkData($dto, $estimate, $item['section_id']);
            
            $matched = false;
            // ... (keeping existing matcher logic) ...
            if ($dto->code) {
                $match = $this->matcher->findByCode($dto->code, ['fallback_to_name' => true, 'name' => $dto->itemName]);
                if ($match && isset($match['normative'])) {
                    $itemData = $this->matcher->fillFromNormative($match['normative'], $itemData);
                    $matched = true;
                }
            }

            if (!$matched && !empty($dto->itemName)) {
                $semanticHit = $this->semanticMatcher->getBestNormativeMatch($dto->itemName, $dto->unit);
                if ($semanticHit && $semanticHit['similarity'] >= 0.5) {
                    $norm = \App\Models\NormativeRate::find($semanticHit['id']);
                    if ($norm) {
                        $itemData = $this->matcher->fillFromNormative($norm, $itemData);
                        $itemDataMetadata = is_string($itemData['metadata']) ? json_decode($itemData['metadata'], true) : $itemData['metadata'];
                        $itemDataMetadata['semantic_match'] = [
                            'similarity' => $semanticHit['similarity'],
                            'matched_name' => $semanticHit['name'],
                        ];
                        $itemData['metadata'] = json_encode($itemDataMetadata);
                        $matched = true;
                        Log::info("[ImportPipeline] SemanticMatch hit for '{$dto->itemName}' → '{$semanticHit['name']}' (sim={$semanticHit['similarity']})");
                    }
                }
            }
            
            if (!$matched) {
                $aiBatch[$index] = [
                    'code' => $dto->code ?? '',
                    'name' => $dto->itemName,
                    'unit' => $dto->unit,
                    'price' => (float)$dto->unitPrice
                ];
            }
            
            $batch[$index]['prepared_data'] = $itemData;
        }

        // Step 2: AI Classification
        if (!empty($aiBatch)) {
            try {
                $aiResults = $this->classifier->classifyBatch(array_values($aiBatch));
                $aiKeys = array_keys($aiBatch);
                
                foreach ($aiResults as $subIndex => $result) {
                    $origIndex = $aiKeys[$subIndex];
                    if (isset($batch[$origIndex])) {
                        $batch[$origIndex]['prepared_data']['item_type'] = $result->type;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("[ImportPipeline] AI Batch Classification failed: " . $e->getMessage());
            }
        }

        // Step 3: Sub-item Grouping (XML Parity)
        $preparedRows = array_column($batch, 'prepared_data');
        $groupedRows  = $this->subItemGrouper->groupItems($preparedRows, $this->subItemState);

        // Step 4: Formula Validation
        $this->formulaAwareness->annotate($groupedRows);

        // Step 5: Global Insert with Hierarchy Awareness
        $childrenBatch = [];
        $insertedParents = [];

        foreach ($groupedRows as $idx => $data) {
            if (isset($data['metadata']) && is_array($data['metadata'])) {
                $data['metadata'] = json_encode($data['metadata']);
            }
            
            $isSubItem = !empty($data['is_sub_item']);
            $parentIndex = $data['_parent_index'] ?? null;
            
            // 🔧 ИСПРАВЛЕНИЕ: Удаляем технические поля, которых нет в БД
            unset(
                $data['is_sub_item'], 
                $data['_parent_index'], 
                $data['warnings'], 
                $data['has_math_mismatch'],
                $data['anomaly']
            );
            
            if (!$isSubItem) {
                // Если накопились дочерние элементы, вставляем их перед родителем, чтобы сохранить относительный порядок БД
                if (!empty($childrenBatch)) {
                    EstimateItem::insert($childrenBatch);
                    $stats['items_created'] += count($childrenBatch);
                    $childrenBatch = [];
                }
                
                // Вставляем родителя отдельно, чтобы получить его ID для связей
                $id = DB::table('estimate_items')->insertGetId($data);
                $insertedParents[$idx] = $id;
                $this->subItemState['last_parent_id'] = $id; // для кросс-батч связей
                $stats['items_created']++;
            } else {
                // Это подпункт, привязываем его к родителю
                if ($parentIndex !== null && isset($insertedParents[$parentIndex])) {
                    $data['parent_work_id'] = $insertedParents[$parentIndex];
                } elseif ($parentIndex === 'prev' && isset($this->subItemState['last_parent_id'])) {
                    // Родитель был в предыдущем батче
                    $data['parent_work_id'] = $this->subItemState['last_parent_id'];
                }
                
                $childrenBatch[] = $data;
            }
        }

        if (!empty($childrenBatch)) {
            EstimateItem::insert($childrenBatch);
            $stats['items_created'] += count($childrenBatch);
        }
    }

    private function saveSection($dto, int $estimateId, array &$sectionMap): EstimateSection
    {
        // Resolve Parent
        $currentPath = $dto->sectionPath ?: $dto->sectionNumber; 
        
        $parentId = null;
        if (str_contains($currentPath, '.')) {
             $parentPath = substr($currentPath, 0, strrpos($currentPath, '.'));
             $parentId = $sectionMap[$parentPath] ?? null;
        }

        $section = EstimateSection::create([
            'estimate_id' => $estimateId,
            'parent_section_id' => $parentId,
            'section_number' => (string)$dto->sectionNumber,
            'full_section_number' => (string)$currentPath,
            'name' => $dto->itemName,
            'sort_order' => $dto->rowNumber, 
        ]);
        
        $sectionMap[$currentPath] = $section->id;
        
        return $section;
    }

    private function resolveSectionId($dto, array $sectionMap, ?int $lastSectionId): ?int
    {
        $path = $dto->sectionPath ?: $dto->sectionNumber;
        if ($path && isset($sectionMap[$path])) {
            return $sectionMap[$path];
        }
        return $lastSectionId;
    }

    private function prepareWorkData($dto, Estimate $estimate, ?int $sectionId): array
    {
        $laborCost = $this->detectLaborCost($dto);
        $isInformative = $this->isInformativeGrandSmetaRow($dto);
        $totalAmount = (float)($dto->currentTotalAmount ?? ($dto->quantity * ($dto->unitPrice ?? 0)));
        
        // 1. Берем точные рублевые значения НР и СП напрямую от парсера ГрандСметы
        $overheadAmount = isset($dto->overheadAmount) ? (float)$dto->overheadAmount : 0;
        $profitAmount = isset($dto->profitAmount) ? (float)$dto->profitAmount : 0;
        
        // 2. Fallback: если парсер не нашел суммы, но есть ФОТ и настройки сметы
        if ($overheadAmount == 0 && $profitAmount == 0 && !$isInformative && $laborCost > 0) {
            $overheadAmount = round($laborCost * ($estimate->overhead_rate / 100), 2);
            $profitAmount = round($laborCost * ($estimate->profit_rate / 100), 2);
        }

        // 3. В Гранд-Смете сумма позиции из колонки "Всего" - это Прямые Затраты!
        $directCosts = $totalAmount;
        
        // Значит Итог с учетом налогов (полная стоимость) - это ПЗ + НР + СП
        // Для подпунктов математика налогов не применяется (их сумма заложена в ПЗ родителя)
        $actualTotalAmount = $directCosts + $overheadAmount + $profitAmount;

        $isSubItem = $dto->isSubItem ?? false;
        
        // 4. Все подпункты делают задвоение, поэтому их исключаем из учета итоговых сумм
        $isNotAccounted = $isInformative || $isSubItem;

        $metadata = [
            'original_unit' => $dto->unit,
            'raw_data' => $dto->rawData,
            'overhead_rate' => $estimate->overhead_rate,
            'profit_rate' => $estimate->profit_rate,
            'is_informative_row' => $isInformative,
        ];

        return [
            'estimate_id' => $estimate->id,
            'estimate_section_id' => $sectionId,
            'name' => $dto->itemName,
            'description' => null,
            'measurement_unit_id' => $this->resolveUnitId($dto->unit, $estimate->organization_id),
            'quantity' => $dto->quantity ?? 0,
            'unit_price' => $dto->unitPrice ?? 0,
            
            'base_unit_price' => $dto->baseUnitPrice ?? 0,
            'price_index' => $dto->priceIndex ?? 1,
            'current_unit_price' => $dto->currentUnitPrice ?? ($dto->unitPrice ?? 0),
            
            'labor_cost' => $laborCost,
            'overhead_amount' => $overheadAmount,
            'profit_amount' => $profitAmount,
            'direct_costs' => $directCosts,
            'total_amount' => $actualTotalAmount,
            'current_total_amount' => $actualTotalAmount,

            'materials_cost' => $dto->materialsCost ?? 0,
            'machinery_cost' => $dto->machineryCost ?? 0,
            'equipment_cost' => $dto->itemType === 'equipment' ? $actualTotalAmount : 0,
            'labor_hours' => $this->detectLaborHours($dto),
            'machinery_hours' => $this->detectMachineryHours($dto),
            
            'normative_rate_code' => $dto->code,
            'position_number' => (string)($dto->sectionNumber ?: ''),
            'item_type' => $this->mapItemType($dto->itemType),
            'is_manual' => true, 
            'is_sub_item' => $isSubItem,
            'created_at' => now(),
            'updated_at' => now(),
            'metadata' => json_encode($metadata),
            'is_not_accounted' => $isNotAccounted
        ];
    }

    /**
     * Помощник для определения "информационных" строк GrandSmeta, 
     * которые не должны участвовать в суммировании Прямых Затрат
     */
    private function isInformativeGrandSmetaRow($dto): bool
    {
        $name = mb_strtolower($dto->itemName ?? '');
        $code = mb_strtolower($dto->code ?? '');
        
        // 1. Агрегирующие заголовки (ОТ, ЭМ, М, ОТм) - они несут ФОТ/ПЗ заголовка,
        // но ниже идут детали с теми же деньгами. Чтобы не двоить - помечаем как инфо.
        $aggregates = ['от(зт)', 'эм', 'отм(зтм)', 'м', 'зтм', 'зт', 'от', 'отм', 'мат'];
        if (in_array($name, $aggregates) && (empty($code) || strlen($code) <= 2)) {
            return true;
        }

        // 2. Строки зарплаты машиниста под конкретной машиной (шифр 4-100-XXX)
        // Их стоимость уже заложена в стоимость самой машины
        if (str_starts_with($code, '4-100-')) {
            return true;
        }

        // 3. Дублирующие информационные строки (редко, но бывает)
        if (str_contains($name, 'всего по позиции')) {
            return true;
        }

        return false;
    }

    /**
     * Помощник для детекции ФОТ из названия или обоснования GrandSmeta
     */
    private function detectLaborCost($dto): float
    {
        if (isset($dto->laborCost) && (float)$dto->laborCost > 0) {
            return (float)$dto->laborCost;
        }

        $name = mb_strtolower($dto->itemName ?? '');
        $code = mb_strtolower($dto->code ?? '');

        // ⭐ ИНКЛЮЗИВНЫЙ ПОИСК ФОТ В РЕСУРСАХ
        // Нам нужно ловить всё, что похоже на зарплату (ОТ, ЗТ, ОТм, ЗТм, ОТ(...)).
        $laborPrefixes = ['от(', 'зт(', 'отм(', 'зтм(', 'от ', 'отм ', 'зт ', 'зтм '];
        $isLaborName = false;
        
        if (in_array($name, ['от', 'отм', 'зт', 'зтм', 'от(зт)', 'отм(зтм)'])) {
            $isLaborName = true;
        } else {
            foreach ($laborPrefixes as $pref) {
                if (str_starts_with($name, $pref)) {
                    $isLaborName = true;
                    break;
                }
            }
        }

        if ($isLaborName) {
            return (float)($dto->currentTotalAmount ?? 0);
        }

        return 0;
    }
    
    private function detectLaborHours($dto): float
    {
        if ($dto->itemType !== 'labor') {
            return 0;
        }

        $unit = mb_strtolower(trim((string)($dto->unit ?? '')));
        $laborUnits = ['чел.-ч', 'чел-ч', 'чел.ч', 'чел/ч'];

        foreach ($laborUnits as $lu) {
            if (str_starts_with($unit, $lu)) {
                return (float)($dto->quantity ?? 0);
            }
        }

        return (float)($dto->quantity ?? 0);
    }

    private function detectMachineryHours($dto): float
    {
        if (!in_array($dto->itemType, ['machinery', 'equipment'], true)) {
            return 0;
        }

        $unit = mb_strtolower(trim((string)($dto->unit ?? '')));
        $machineUnits = ['маш.-ч', 'маш-ч', 'маш.ч', 'маш/ч'];

        foreach ($machineUnits as $mu) {
            if (str_starts_with($unit, $mu)) {
                return (float)($dto->quantity ?? 0);
            }
        }

        return 0;
    }

    private function mapItemType(?string $type): string
    {
        return match($type) {
            'material' => 'material',
            'machinery' => 'machinery',
            'labor' => 'labor', 
            'equipment' => 'equipment',
            'summary' => 'summary',
            default => 'work'
        };
    }
    


    private function resolveUnitId(?string $unitName, int $organizationId): ?int
    {
        if (empty($unitName)) {
            return null;
        }

        $normalized = mb_strtolower(trim($unitName));
        $cacheKey = "{$organizationId}:{$normalized}";

        if (array_key_exists($cacheKey, $this->unitCache)) {
            return $this->unitCache[$cacheKey];
        }

        $unit = MeasurementUnit::where('organization_id', $organizationId)
            ->where(function ($q) use ($normalized) {
                $q->whereRaw('LOWER(short_name) = ?', [$normalized])
                  ->orWhereRaw('LOWER(name) = ?', [$normalized]);
            })
            ->first();

        if (!$unit) {
            $unit = MeasurementUnit::create([
                'organization_id' => $organizationId,
                'name'            => $unitName,
                'short_name'      => $unitName,
                'type'            => 'material',
                'is_system'       => false,
            ]);
        }

        $this->unitCache[$cacheKey] = $unit->id;

        return $unit->id;
    }
}
