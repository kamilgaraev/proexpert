<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Strategies\ItemImportStrategyInterface;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Strategies\WorkStrategy;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Strategies\ResourceStrategy;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Strategies\SummaryStrategy;
use App\Models\Estimate;
use Illuminate\Support\Facades\Log;

class EstimateItemProcessor
{
    /** @var ItemImportStrategyInterface[] */
    private array $strategies;

    public function __construct(
        WorkStrategy $workStrategy,
        ResourceStrategy $resourceStrategy,
        SummaryStrategy $summaryStrategy
    ) {
        // Порядок важен: более специфичные стратегии первыми
        $this->strategies = [
            $resourceStrategy,
            $summaryStrategy,
            $workStrategy,
        ];
    }

    public function processItems(
        Estimate $estimate, 
        array $rows, 
        ImportContext $context,
        ImportProgressTracker $progressTracker
    ): array {
        $totalItems = count($rows);
        
        Log::info('[EstimateItemProcessor] Starting processing', [
            'total_items' => $totalItems,
            'estimate_id' => $estimate->id
        ]);

        foreach ($rows as $index => $row) {
            // Конвертируем в DTO если это массив
            if (is_array($row)) {
                $rowDTO = EstimateImportRowDTO::fromArray($row);
            } elseif ($row instanceof EstimateImportRowDTO) {
                $rowDTO = $row;
            } else {
                Log::warning('[EstimateItemProcessor] Invalid row format', ['row_index' => $index]);
                $context->skippedCount++;
                continue;
            }

            try {
                // Обновляем прогресс (50-85%)
                $progressTracker->update($index, $totalItems, 50, 85);
                
                // Обновляем контекст раздела
                $this->updateContextSection($rowDTO, $context);
                
                // Делегируем стратегии
                $processed = false;
                foreach ($this->strategies as $strategy) {
                    if ($strategy->canHandle($rowDTO)) {
                        $strategy->process($rowDTO, $context);
                        $context->incrementStat($rowDTO->itemType);
                        $context->importedCount++;
                        $processed = true;
                        break;
                    }
                }
                
                if (!$processed) {
                    Log::warning('[EstimateItemProcessor] No strategy found for item', [
                        'row' => $rowDTO->rowNumber,
                        'type' => $rowDTO->itemType
                    ]);
                    $context->skippedCount++;
                }
                
            } catch (\Exception $e) {
                Log::error('[EstimateItemProcessor] Error processing item', [
                    'row' => $rowDTO->rowNumber,
                    'error' => $e->getMessage()
                ]);
                $context->skippedCount++;
            }
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

    private function updateContextSection(EstimateImportRowDTO $row, ImportContext $context): void
    {
        if (!empty($row->sectionPath) && isset($context->sectionsMap[$row->sectionPath])) {
            $context->currentSectionId = $context->sectionsMap[$row->sectionPath];
        } else {
            $context->currentSectionId = null; 
        }
    }
}
