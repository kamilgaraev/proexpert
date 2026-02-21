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
                    
                    // Set global markup rates if they are not already set
                    if (($estimate->overhead_rate ?? 0) <= 0 || ($estimate->profit_rate ?? 0) <= 0) {
                        $directCosts = (float)($footerData['direct_costs'] ?? 0);
                        $laborCost = (float)($footerData['labor_cost'] ?? 0); // ФОТ
                        $overhead = (float)($footerData['overhead_cost'] ?? 0);
                        $profit = (float)($footerData['profit_cost'] ?? 0);
                        
                        $baseForRate = $laborCost > 0 ? $laborCost : ($directCosts > 0 ? $directCosts : 0);
                        
                        if ($baseForRate > 0) {
                            if ($overhead > 0) {
                                $estimate->overhead_rate = round(($overhead / $baseForRate) * 100, 2);
                            }
                            if ($profit > 0) {
                                $estimate->profit_rate = round(($profit / $baseForRate) * 100, 2);
                            }
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
                ($rowDTO->quantity === null || $rowDTO->quantity <= 0) && 
                ($rowDTO->unitPrice === null || $rowDTO->unitPrice <= 0) &&
                ($rowDTO->currentTotalAmount === null || $rowDTO->currentTotalAmount <= 0)
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
            
            // Обновляем прогресс каждые 10 строк (сдвинуто: 50% → 88% для Excel)
            if ($stats['processed_rows'] % 10 === 0) {
                $totalRows = $stats['total_rows'] ?? 0;
                $progress = $totalRows > 0
                    ? (int) (50 + min(38, (($stats['processed_rows'] / $totalRows) * 38)))
                    : min(88, 50 + (int) ($stats['processed_rows'] / 5));

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
            $itemData = $this->prepareWorkData($dto, $estimate->id, $item['section_id'], $estimate->organization_id);
            
            $matched = false;
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
                        $itemData['metadata']['semantic_match'] = [
                            'similarity' => $semanticHit['similarity'],
                            'matched_name' => $semanticHit['name'],
                        ];
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

    private function prepareWorkData($dto, int $estimateId, ?int $sectionId, int $organizationId): array
    {
        return [
            'estimate_id' => $estimateId,
            'estimate_section_id' => $sectionId,
            'name' => $dto->itemName,
            'measurement_unit_id' => $this->resolveUnitId($dto->unit, $organizationId),
            
            'quantity' => $dto->quantity ?? 0,
            'unit_price' => $dto->unitPrice ?? 0,
            
            // Base & Index fields
            'base_unit_price' => $dto->baseUnitPrice ?? 0,
            'price_index' => $dto->priceIndex ?? 1,
            'current_unit_price' => $dto->currentUnitPrice ?? ($dto->unitPrice ?? 0),
            
            // Detailed Base Costs
            'base_materials_cost' => $dto->baseMaterialsCost ?? 0,
            'base_machinery_cost' => $dto->baseMachineryCost ?? 0,
            'base_machinery_labor_cost' => $dto->baseMachineryLaborCost ?? 0,
            'base_labor_cost' => $dto->baseLaborCost ?? 0,
            
            // Base Overhead & Profit (from text, e.g. "НР (28,38 руб)...")
            // These are already row totals in typical FER export, so we don't multiply by quantity again here
            // to avoid double-counting if quantity > 1.
            'base_overhead_amount' => round($dto->overheadAmount ?? 0, 2),
            'base_profit_amount' => round($dto->profitAmount ?? 0, 2),
            
            // Current Overhead & Profit (Base * Index)
            'overhead_amount' => round(($dto->overheadAmount ?? 0) * ($dto->priceIndex ?? 1), 2),
            'profit_amount' => round(($dto->profitAmount ?? 0) * ($dto->priceIndex ?? 1), 2),
            
            'direct_costs' => round($dto->currentTotalAmount ?? ($dto->quantity ?? 0) * ($dto->unitPrice ?? 0), 2),
            'total_amount' => round($dto->currentTotalAmount ?? ($dto->quantity ?? 0) * ($dto->unitPrice ?? 0), 2),
            'current_total_amount' => $dto->currentTotalAmount !== null ? round($dto->currentTotalAmount, 2) : null,
            'normative_rate_code' => $dto->code,
            'position_number' => (string)($dto->sectionNumber ?: ''),
            'item_type' => $this->mapItemType($dto->itemType),
            'is_manual' => true, 
            'is_sub_item' => $dto->isSubItem ?? false, // ⭐ Передаем флаг для группировщика
            'created_at' => now(),
            'updated_at' => now(),
            'metadata' => json_encode([
                'original_unit' => $dto->unit,
                'raw_data' => $dto->rawData,
                'overhead_rate' => $dto->overheadRate,
                'profit_rate' => $dto->profitRate,
            ])
        ];
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
