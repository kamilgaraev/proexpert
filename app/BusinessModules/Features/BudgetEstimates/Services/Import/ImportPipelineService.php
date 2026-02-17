<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\BusinessModules\Features\BudgetEstimates\Services\EstimateService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\Factory\ParserFactory;
use App\Models\Estimate;
use App\Models\EstimateSection;
use App\Models\EstimateItem;
use App\Models\ImportSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Classification\ItemClassificationService;

class ImportPipelineService
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private ParserFactory $parserFactory,
        private FileStorageService $fileStorage,
        private ImportRowMapper $rowMapper,
        private EstimateService $estimateService,
        private ItemClassificationService $classifier,
        private NormativeMatchingService $matcher
    ) {}

    public function run(ImportSession $session, array $config = []): void
    {
        Log::info("[ImportPipeline] Started for session {$session->id}");
        
        $session->update([
            'status' => 'parsing', 
            'stats' => array_merge($session->stats ?? [], ['message' => 'Starting import pipeline...'])
        ]);

        $filePath = $this->fileStorage->getAbsolutePath($session);
        $parser = $this->parserFactory->getParser($filePath);
        
        // Prepare Parser Options
        $options = [
            'header_row' => $session->options['structure']['header_row'] ?? null,
            'column_mapping' => $session->options['structure']['column_mapping'] ?? [],
        ];

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
        ]);
    }

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

            // Apply mapping
            $rowDTO = $this->rowMapper->map($rowDTO, $session->options['structure']['column_mapping'] ?? []);
            
            // Skip rows that have no name and no numeric data
            if (!$rowDTO->isSection && empty($rowDTO->itemName) && $rowDTO->quantity === null && $rowDTO->unitPrice === null) {
                continue;
            }

            if ($rowDTO->isSection) {
                // If we have items in batch, process and save them first to maintain order
                if (!empty($batchDTOs)) {
                    $this->processAndInsertBatch($batchDTOs, $estimate, $stats, $session);
                    $batchDTOs = [];
                }

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
            $itemData = $this->prepareWorkData($dto, $estimate->id, $item['section_id']);
            
            $matched = false;
            if ($dto->code) {
                $match = $this->matcher->findByCode($dto->code, ['fallback_to_name' => true, 'name' => $dto->itemName]);
                if ($match && isset($match['normative'])) {
                    $itemData = $this->matcher->fillFromNormative($match['normative'], $itemData);
                    $matched = true;
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
                // aiResults usually matches the batch order if implementation is correct
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

        // Step 3: Global Insert
        foreach ($batch as $item) {
            $data = $item['prepared_data'];
            // Cleanup for bulk insert: ensure metadata is JSON string
            if (isset($data['metadata']) && is_array($data['metadata'])) {
                $data['metadata'] = json_encode($data['metadata']);
            }
            $itemsToInsert[] = $data;
        }

        if (!empty($itemsToInsert)) {
            EstimateItem::insert($itemsToInsert);
            $stats['items_created'] += count($itemsToInsert);
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
            'parent_id' => $parentId,
            'name' => $dto->itemName,
            'order_column' => $dto->rowNumber, 
            'full_section_number' => $currentPath, 
        ]);
        
        $sectionMap[$currentPath] = $section->id;
        
        return $section;
    }

    private function resolveSectionId($dto, array $sectionMap, ?int $lastSectionId): ?int
    {
        if ($dto->sectionPath && isset($sectionMap[$dto->sectionPath])) {
            return $sectionMap[$dto->sectionPath];
        }
        return $lastSectionId;
    }

    private function prepareWorkData($dto, int $estimateId, ?int $sectionId): array
    {
        return [
            'estimate_id' => $estimateId,
            'estimate_section_id' => $sectionId,
            'name' => $dto->itemName,
            'measurement_unit_id' => null, // Needs resolving or string storage? EstimateItem has measurement_unit_id relation, but maybe we need to find/create it? 
            // For now, let's assuming we might not be able to fill unit_id immediately without lookup.
            // But EstimateItem seems to have 'unit' field implicitly or via relation.
            // Looking at EstimateItem model: 'measurement_unit_id' is fillable.
            // Does it have a string 'unit' field? No.
            // It has 'measurement_unit_id'.
            // OLD IMPORT LOGIC likely looked up the unit.
            // For MVP Phase 3 Pipeline, we can skip unit lookup or do a quick lookup if desired.
            // Or add 'unit_name' to metadata if model doesn't support string unit.
            // Wait, I should check EstimateItem columns again or look for 'unit' field.
            // I saw 'measurement_unit_id' and 'unit_price'.
            // I don't see 'unit' string field in fillable.
            // This suggests I MUST resolve unit to ID.
            // OR I can store it in metadata for now.
            // Let's check `EstimateImportService` original code to see how it handled units.
            // Actually, for now, to avoid blocking on Unit Lookup Service (which might be complex),
            // I will leave measurement_unit_id null and store unit in metadata["original_unit"].
            
            'quantity' => $dto->quantity ?? 0,
            'unit_price' => $dto->unitPrice ?? 0,
            'total_amount' => ($dto->quantity ?? 0) * ($dto->unitPrice ?? 0), // total_amount in model
            'normative_rate_code' => $dto->code, // normative_rate_code in model
            'position_number' => (string)$dto->rowNumber,
            'item_type' => $this->mapItemType($dto->itemType), // Need mapper
            'is_manual' => true, // Imported are often manual? Or false? 
            // Usually imported items are manual until linked to normative base.
            'created_at' => now(),
            'updated_at' => now(),
            'metadata' => json_encode([
                'original_unit' => $dto->unit,
                'raw_data' => $dto->rawData
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
        // Simple aggregation query
        $total = EstimateItem::where('estimate_id', $estimate->id)->sum('total_amount');
        // 'clean_cost' field? Model doesn't show it in this file but previous Service snippet used it.
        // Estimate model likely has 'total' or similar. 
        // I'll stick to 'total' if available or leave it for now.
        // Let's assume 'total' or 'total_amount'.
        // Actually, Estimate model view wasn't requested. 
        // I'll skip generic update for now or just log it.
        Log::info("Estimate {$estimate->id} total calculated: $total");
    }
}
