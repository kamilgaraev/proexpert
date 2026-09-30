<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Observers;

use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateItemResource;
use App\Models\EstimateSection;
use Illuminate\Database\Eloquent\Model;

final class EstimateRagIndexObserver
{
    public function saved(Model $model): void
    {
        $this->queue($model);
    }

    public function deleted(Model $model): void
    {
        $this->queue($model);
    }

    public function restored(Model $model): void
    {
        $this->queue($model);
    }

    private function queue(Model $model): void
    {
        $transactional = \Illuminate\Support\Facades\DB::transactionLevel() > 0;
        try {
            $this->queueEntity($model);
        } catch (\Throwable $exception) {
            if ($transactional) {
                throw $exception;
            }
            \Illuminate\Support\Facades\Log::warning('ai_assistant.rag.estimate_queue_failed', [
                'model' => $model::class, 'entity_id' => (string) $model->getKey(), 'exception_class' => $exception::class,
            ]);
        }
    }

    private function queueEntity(Model $model): void
    {
        if (app(\App\BusinessModules\Features\AIAssistant\Services\AssistantIndexingState::class)->paused()) {
            return;
        }
        [$organizationId, $projectId, $entityType] = match (true) {
            $model instanceof Estimate => [$model->organization_id, $model->project_id, 'estimate'],
            $model instanceof EstimateSection => [...$this->sectionScope($model), 'estimate_section'],
            $model instanceof EstimateItem => [...$this->itemScope($model), 'estimate_item'],
            $model instanceof EstimateItemResource => [...$this->resourceScope($model), 'estimate_item_resource'],
            default => [null, null, null],
        };

        if (! is_int($organizationId) || $organizationId < 1 || ! is_string($entityType)) {
            return;
        }

        app(RagIndexingCoordinator::class)->queueEntity(
            $organizationId,
            is_int($projectId) ? $projectId : null,
            'estimate',
            $entityType,
            $model->getKey()
        );
        if (! $model instanceof Estimate) {
            $estimateId = $model instanceof EstimateItemResource
                ? EstimateItem::withTrashed()->whereKey($model->estimate_item_id)->value('estimate_id')
                : $model->getAttribute('estimate_id');
            if (is_numeric($estimateId)) {
                app(RagIndexingCoordinator::class)->queueEntity($organizationId, $projectId, 'estimate', 'estimate_summary', (int) $estimateId);
                $sectionId = $model instanceof EstimateSection ? $model->id : ($model instanceof EstimateItemResource
                    ? EstimateItem::withTrashed()->whereKey($model->estimate_item_id)->value('estimate_section_id')
                    : $model->getAttribute('estimate_section_id'));
                $seen = [];
                while (is_numeric($sectionId) && ! isset($seen[(int) $sectionId])) {
                    $seen[(int) $sectionId] = true;
                    app(RagIndexingCoordinator::class)->queueEntity($organizationId, $projectId, 'estimate', 'estimate_section', (int) $sectionId);
                    $sectionId = EstimateSection::query()->whereKey($sectionId)->value('parent_section_id');
                }
            }
        }
    }

    private function sectionScope(EstimateSection $section): array
    {
        return $this->estimateScope((int) $section->estimate_id);
    }

    private function itemScope(EstimateItem $item): array
    {
        return $this->estimateScope((int) $item->estimate_id);
    }

    private function resourceScope(EstimateItemResource $resource): array
    {
        $estimateId = EstimateItem::withTrashed()->whereKey($resource->estimate_item_id)->value('estimate_id');

        return is_numeric($estimateId) ? $this->estimateScope((int) $estimateId) : [null, null];
    }

    private function estimateScope(int $estimateId): array
    {
        $estimate = Estimate::withTrashed()->find($estimateId);

        return $estimate instanceof Estimate
            ? [(int) $estimate->organization_id, $estimate->project_id === null ? null : (int) $estimate->project_id]
            : [null, null];
    }
}
