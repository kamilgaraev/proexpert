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
        private FormulaAwarenessService $formulaAwareness
    ) {}

    public function run(ImportSession $session, array $config = []): void
    {
        Log::info("[ImportPipeline] Started for session {$session->id}");
        
        $session->update([
            'status' => 'parsing', 
            'stats' => array_merge($session->stats ?? [], ['message' => 'Starting import pipeline...'])
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

        // 1. Create or Get Estimate
        $estimate = $this->resolveEstimate($session);
        
        // 2. Stream & Process
        $stream = $parser->getStream($filePath, $options);
        
        $stats = [
            'processed_rows' => 0,
            'sections_created' => 0,
            'items_created' => 0,
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
                    // Currently we store it in estimate's metadata
                    $meta = $estimate->metadata ?? [];
                    $meta['footer'] = $footerData;
                    $estimate->metadata = $meta; 
                    $estimate->save();
                }
            }

            // Update Totals
            $this->updateEstimateTotals($estimate);
            
            DB::commit();
            
            Log::info("[ImportPipeline] Finished for session {$session->id}", $stats);
            
            $session->update([
                'status' => 'completed',
                'stats' => array_merge($session->stats ?? [], [
                    'progress' => 100, 
                    'result' => $stats, 
                    'estimate_id' => $estimate->id,
                    'message' => 'Import successfully completed.'
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
            
            // Progress update for the user
            if ($stats['processed_rows'] % 50 === 0) {
                 $session->update(['stats' => array_merge($session->stats ?? [], [
                     'message' => "Processed {$stats['processed_rows']} rows...",
                     'processed_rows' => $stats['processed_rows']
                 ])]); 
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
    
    private function updateEstimateTotals(Estimate $estimate): void
    {
        $totals = EstimateItem::where('estimate_id', $estimate->id)
            ->whereNull('parent_work_id')
            ->where('is_not_accounted', false)
            ->selectRaw('
                -- Current Totals: round each row before sum to match Excel behavior
                SUM(ROUND(CAST(direct_costs AS NUMERIC), 2)) as direct_costs,
                SUM(ROUND(CAST(overhead_amount AS NUMERIC), 2)) as overhead_amount,
                SUM(ROUND(CAST(profit_amount AS NUMERIC), 2)) as profit_amount,
                
                -- Base Totals: round each product before sum
                SUM(ROUND(CAST(base_unit_price AS NUMERIC) * CAST(quantity AS NUMERIC), 2)) as base_direct_costs,
                SUM(ROUND(CAST(base_materials_cost AS NUMERIC) * CAST(quantity AS NUMERIC), 2)) as base_materials_cost,
                SUM(ROUND(CAST(base_machinery_cost AS NUMERIC) * CAST(quantity AS NUMERIC), 2)) as base_machinery_cost,
                SUM(ROUND(CAST(base_labor_cost AS NUMERIC) * CAST(quantity AS NUMERIC), 2)) as base_labor_cost,
                SUM(ROUND(CAST(base_machinery_labor_cost AS NUMERIC) * CAST(quantity AS NUMERIC), 2)) as base_machinery_labor_cost,
                
                SUM(base_overhead_amount) as base_overhead_total,
                SUM(base_profit_amount) as base_profit_total
            ')
            ->first();

        if ($totals) {
            $totalDirect = round((float)($totals->direct_costs ?? 0), 2);
            $totalOverhead = round((float)($totals->overhead_amount ?? 0), 2);
            $totalProfit = round((float)($totals->profit_amount ?? 0), 2);
            
            // Excel Total = Direct + OH + Profit
            $totalWithoutVat = round($totalDirect + $totalOverhead + $totalProfit, 2);
            
            $vatRate = (float)($estimate->vat_rate ?? self::DEFAULT_VAT_RATE);
            // НДС считается от уже округленной суммы без НДС
            $totalWithVat = round(round($totalWithoutVat, 2) * (1 + ($vatRate / 100)), 2);

            $estimate->update([
                'total_amount' => $totalWithoutVat,
                'total_direct_costs' => $totalDirect,
                'total_overhead_costs' => $totalOverhead,
                'total_estimated_profit' => $totalProfit,
                'total_amount_with_vat' => $totalWithVat,
                'vat_rate' => $vatRate,
                
                // Base fields
                'total_base_direct_costs' => round((float)($totals->base_direct_costs ?? 0), 2),
                'total_base_materials_cost' => round((float)($totals->base_materials_cost ?? 0), 2),
                'total_base_machinery_cost' => round((float)($totals->base_machinery_cost ?? 0), 2),
                // ФОТ (Base) = ЗП + ЗПМ
                'total_base_labor_cost' => round((float)(($totals->base_labor_cost ?? 0) + ($totals->base_machinery_labor_cost ?? 0)), 2),
                'total_base_overhead_amount' => round((float)($totals->base_overhead_total ?? 0), 2),
                'total_base_profit_amount' => round((float)($totals->base_profit_total ?? 0), 2),
            ]);
        }

        Log::info("Estimate {$estimate->id} totals updated", ['total' => $estimate->total_amount_with_vat]);
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
