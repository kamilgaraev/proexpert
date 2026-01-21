<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Strategies\ItemImportStrategyInterface;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Strategies\WorkStrategy;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Strategies\ResourceStrategy;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Strategies\SummaryStrategy;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Classification\ItemClassificationService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Validation\MathValidatorService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Validation\UnitNormalizationService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Context\StackSectionContext;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateSectionService;
use App\Models\Estimate;
use Illuminate\Support\Facades\Log;

class EstimateItemProcessor
{
    /** @var ItemImportStrategyInterface[] */
    private array $strategies;

    public function __construct(
        WorkStrategy $workStrategy,
        ResourceStrategy $resourceStrategy,
        SummaryStrategy $summaryStrategy,
        private ItemClassificationService $classificationService,
        private MathValidatorService $mathValidator,
        private UnitNormalizationService $unitNormalizer,
        private EstimateSectionService $sectionService,
        private StackSectionContext $sectionStack
    ) {
        // Порядок важен: более специфичные стратегии первыми
        $this->strategies = [
            $resourceStrategy,
            $summaryStrategy,
            $workStrategy,
        ];
    }

    /**
     * Обрабатывает поток данных (массив или генератор)
     */
    public function processItems(
        Estimate $estimate, 
        iterable $rows, 
        ImportContext $context,
        ImportProgressTracker $progressTracker
    ): array {
        $totalItems = is_array($rows) ? count($rows) : 0; // Для генератора может быть неизвестно
        
        Log::info('[EstimateItemProcessor] Starting processing stream', [
            'estimate_id' => $estimate->id
        ]);
        
        $this->sectionStack->reset();
        
        $batch = [];
        $batchSize = 50;
        $processedCount = 0;

        foreach ($rows as $index => $row) {
            // Конвертируем в DTO
            if (is_array($row)) {
                // Если это массив из старого парсера, там уже есть ключи. 
                // Если из нового stream parser (список значений), нужно маппить.
                // Предположим, что здесь приходят уже подготовленные ассоциативные массивы или DTO.
                // EstimateImportService должен позаботиться о маппинге колонок перед передачей сюда.
                $rowDTO = EstimateImportRowDTO::fromArray($row);
            } elseif ($row instanceof EstimateImportRowDTO) {
                $rowDTO = $row;
            } else {
                continue;
            }

            $batch[] = $rowDTO;

            if (count($batch) >= $batchSize) {
                $this->processBatch($estimate, $batch, $context);
                $processedCount += count($batch);
                
                if ($totalItems > 0) {
                    $progressTracker->update($processedCount, $totalItems, 50, 85);
                }
                
                $batch = [];
            }
        }

        if (!empty($batch)) {
            $this->processBatch($estimate, $batch, $context);
            $processedCount += count($batch);
        }
        
        Log::info('[EstimateItemProcessor] Completed', [
            'imported' => $context->importedCount,
            'skipped' => $context->skippedCount
        ]);
        
        return [
            'imported' => $context->importedCount,
            'skipped' => $context->skippedCount,
            'code_matches' => $context->codeMatchesCount,
            'name_matches' => $context->nameMatchesCount,
            'types_breakdown' => $context->typeStats
        ];
    }

    private function processBatch(Estimate $estimate, array $batch, ImportContext $context): void
    {
        // 1. Batch Classification
        // Prepare items for classification
        $itemsToClassify = [];
        foreach ($batch as $index => $dto) {
            // Классифицируем только если тип еще не определен жестко или требует уточнения
            // Но наша стратегия "Smart" подразумевает перепроверку всех
            $itemsToClassify[$index] = [
                'code' => $dto->code,
                'name' => $dto->itemName,
                'unit' => $dto->unit,
                'price' => $dto->unitPrice
            ];
        }

        $classificationResults = $this->classificationService->classifyBatch($itemsToClassify);

        // 2. Process each item
        foreach ($batch as $index => $dto) {
            /** @var EstimateImportRowDTO $dto */
            
            // Apply classification
            if (isset($classificationResults[$index])) {
                $result = $classificationResults[$index];
                $dto->itemType = $result->type;
                $dto->confidenceScore = $result->confidenceScore;
                $dto->classificationSource = $result->source;
            }

            // Apply normalization
            if ($dto->unit) {
                $dto->unit = $this->unitNormalizer->normalize($dto->unit);
            }

            // Apply validation
            $mathWarnings = $this->mathValidator->validateRow($dto->quantity, $dto->unitPrice, $dto->currentTotalAmount);
            if (!empty($mathWarnings)) {
                $dto->hasMathMismatch = true;
                foreach ($mathWarnings as $warning) {
                    $dto->addWarning($warning);
                }
            }

            // Handle Sections (Stack Machine)
            if ($dto->isSection) {
                $this->handleSection($estimate, $dto, $context);
                $context->importedCount++; // Считаем разделы как импортированные элементы
                continue;
            }

            // Set current section from stack
            $currentSectionId = $this->sectionStack->getCurrentSectionId();
            
            // Если раздела нет в стеке, но в DTO есть sectionPath (из старого парсера), используем его
            if ($currentSectionId === null && $dto->sectionPath && isset($context->sectionsMap[$dto->sectionPath])) {
                $currentSectionId = $context->sectionsMap[$dto->sectionPath];
            }
            
            $context->currentSectionId = $currentSectionId;

            // Process Item Strategies
            $processed = false;
            foreach ($this->strategies as $strategy) {
                if ($strategy->canHandle($dto)) {
                    try {
                        $strategy->process($dto, $context);
                        $context->incrementStat($dto->itemType);
                        $context->importedCount++;
                        $processed = true;
                    } catch (\Exception $e) {
                         Log::error('[EstimateItemProcessor] Strategy error', [
                            'strategy' => get_class($strategy),
                            'error' => $e->getMessage()
                        ]);
                    }
                    break;
                }
            }

            if (!$processed) {
                $context->skippedCount++;
            }
        }
    }

    private function handleSection(Estimate $estimate, EstimateImportRowDTO $dto, ImportContext $context): void
    {
        // Создаем раздел
        $parentSectionId = $this->sectionStack->getParentSectionId($dto->level);
        
        try {
            $section = $this->sectionService->createSection([
                'estimate_id' => $estimate->id,
                'section_number' => $dto->sectionNumber,
                'name' => $dto->itemName,
                'parent_section_id' => $parentSectionId,
            ]);
            
            // Push to stack
            $this->sectionStack->pushSection($section->id, $dto->level);
            
            Log::debug('[EstimateItemProcessor] Section created', [
                'id' => $section->id,
                'number' => $dto->sectionNumber,
                'level' => $dto->level,
                'path' => $dto->sectionPath
            ]);
            
            // Save to map for Path Mode lookups
            if ($dto->sectionPath) {
                $context->sectionsMap[$dto->sectionPath] = $section->id;
            }
            
        } catch (\Exception $e) {
            Log::error('[EstimateItemProcessor] Failed to create section', ['error' => $e->getMessage()]);
        }
    }
}
