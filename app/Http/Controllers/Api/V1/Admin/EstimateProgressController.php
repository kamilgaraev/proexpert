<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\BudgetEstimates\Services\Integration\JournalEstimateIntegrationService;
use App\Http\Responses\AdminResponse;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\ConstructionJournalEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EstimateProgressController extends Controller
{
    public function __construct(
        protected JournalEstimateIntegrationService $integrationService
    ) {}

    /**
     * Получить сравнение плановых и фактических объемов по смете
     */
    public function getActualVsPlanned(Request $request, int $projectId, int $estimateId): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $estimate = Estimate::where('id', $estimateId)
            ->where('project_id', $projectId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        $comparison = $this->integrationService->getActualVsPlannedVolumes($estimate);

        return AdminResponse::success(['items' => $comparison]);
    }

    /**
     * Получить статистику выполнения сметы
     */
    public function getCompletionStats(Request $request, int $projectId, int $estimateId): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $estimate = Estimate::where('id', $estimateId)
            ->where('project_id', $projectId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        $stats = $this->integrationService->getEstimateCompletionStats($estimate);

        // Дополнительно вычисляем статусы позиций
        $items = $estimate->items()->get();
        $completedItems = 0;
        $inProgressItems = 0;
        $notStartedItems = 0;

        foreach ($items as $item) {
            $completionPercent = $item->getCompletionPercentage();
            if ($completionPercent >= 100) {
                $completedItems++;
            } elseif ($completionPercent > 0) {
                $inProgressItems++;
            } else {
                $notStartedItems++;
            }
        }

        return AdminResponse::success([
            'total_items' => $stats['total_items'],
            'completed_items' => $completedItems,
            'in_progress_items' => $inProgressItems,
            'not_started_items' => $notStartedItems,
            'overall_completion_percentage' => $stats['overall_completion_percent'],
            'total_planned_amount' => $stats['estimated_amount'],
            'total_actual_amount' => $stats['completed_amount'],
        ]);
    }

    /**
     * Получить записи журнала для конкретной позиции сметы
     */
    public function getItemJournalEntries(Request $request, int $projectId, int $estimateId, int $itemId): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $estimate = Estimate::where('id', $estimateId)
            ->where('project_id', $projectId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        $item = EstimateItem::where('id', $itemId)
            ->where('estimate_id', $estimateId)
            ->firstOrFail();

        // Получаем все записи журнала, связанные с этой позицией через work_volumes
        $entries = ConstructionJournalEntry::query()
            ->whereHas('workVolumes', function ($query) use ($itemId) {
                $query->where('estimate_item_id', $itemId);
            })
            ->where('estimate_id', $estimateId)
            ->with(['workVolumes' => function ($query) use ($itemId) {
                $query->where('estimate_item_id', $itemId)
                    ->with('measurementUnit');
            }])
            ->orderBy('entry_date', 'desc')
            ->get();

        $result = $entries->map(function ($entry) use ($itemId) {
            $volume = $entry->workVolumes->firstWhere('estimate_item_id', $itemId);
            
            return [
                'id' => $entry->id,
                'entry_date' => $entry->entry_date->format('Y-m-d'),
                'entry_number' => $entry->entry_number,
                'work_description' => $entry->work_description,
                'volume' => (float) ($volume->quantity ?? 0),
                'measurement_unit' => $volume ? [
                    'id' => $volume->measurementUnit?->id,
                    'name' => $volume->measurementUnit?->name,
                ] : null,
            ];
        });

        return AdminResponse::success($result);
    }
}

