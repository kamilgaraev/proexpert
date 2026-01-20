<?php

namespace App\BusinessModules\Addons\AIEstimates\Services\Export;

use App\BusinessModules\Addons\AIEstimates\Models\AIGenerationHistory;
use App\BusinessModules\Features\BudgetEstimates\Services\Export\EstimateExportService;
use App\Models\Estimate;
use App\Models\EstimateSection;
use App\Models\EstimateItem;

class AIEstimateExportService
{
    public function __construct(
        protected EstimateExportService $estimateExportService
    ) {}

    public function export(AIGenerationHistory $generation, string $format = 'pdf'): array
    {
        // Создаем временную смету из draft для экспорта
        $estimate = $this->createTemporaryEstimate($generation);

        // Используем существующий EstimateExportService
        return match($format) {
            'pdf' => $this->estimateExportService->exportToPdf($estimate),
            'excel' => $this->estimateExportService->exportToExcel($estimate),
            default => throw new \InvalidArgumentException("Unsupported export format: {$format}"),
        };
    }

    protected function createTemporaryEstimate(AIGenerationHistory $generation): Estimate
    {
        $draft = $generation->generated_estimate_draft;

        if (empty($draft)) {
            throw new \Exception('No draft estimate data available');
        }

        // Создаем объект Estimate (не сохраняем в БД)
        $estimate = new Estimate(array_merge(
            $draft['estimate_data'] ?? [],
            [
                'id' => null, // временный estimate
                'organization_id' => $generation->organization_id,
                'project_id' => $generation->project_id,
            ]
        ));

        // Добавляем разделы
        $sections = collect($draft['sections'] ?? [])->map(function ($sectionData, $index) {
            return new EstimateSection(array_merge($sectionData, [
                'id' => null,
                'estimate_id' => null,
            ]));
        });

        // Добавляем позиции
        $items = collect($draft['items'] ?? [])->map(function ($itemData, $index) {
            return new EstimateItem(array_merge($itemData, [
                'id' => null,
                'estimate_id' => null,
            ]));
        });

        // Устанавливаем relationships
        $estimate->setRelation('sections', $sections);
        $estimate->setRelation('items', $items);

        return $estimate;
    }

    public function isFormatSupported(string $format): bool
    {
        return in_array($format, ['pdf', 'excel']);
    }
}
