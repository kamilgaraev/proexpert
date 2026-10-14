<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateItemResource;
use App\Models\EstimateSection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class EstimateRagMutationBatch
{
    private array $frames = [];

    public function run(Estimate $estimate, Collection $items, callable $callback): mixed
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Estimate RAG mutation batch requires a transaction.');
        }
        $frameIndex = count($this->frames);
        $this->frames[$frameIndex] = ['estimate' => $estimate, 'items' => $items->keyBy('id'), 'entities' => [], 'sections' => null];

        try {
            $result = $callback();
            $frame = $this->frames[$frameIndex];
            unset($this->frames[$frameIndex]);
            $entities = [];
            foreach ($frame['entities'] as $type => $ids) {
                foreach ($ids as $id) {
                    $entities[] = ['type' => $type, 'id' => $id];
                }
            }
            foreach (array_chunk($entities, 500) as $chunk) {
                $groups = [];
                foreach ($chunk as $entity) {
                    $groups[$entity['type']][] = $entity['id'];
                }
                app(RagIndexingCoordinator::class)->queueEntityGroups(
                    (int) $estimate->organization_id,
                    $estimate->project_id !== null ? (int) $estimate->project_id : null,
                    'estimate',
                    $groups,
                );
            }

            return $result;
        } finally {
            unset($this->frames[$frameIndex]);
        }
    }

    public function capture(Model $model): bool
    {
        $frameIndex = array_key_last($this->frames);
        if ($frameIndex === null) {
            return false;
        }
        $frame = &$this->frames[$frameIndex];
        $estimateId = (int) $frame['estimate']->id;
        $item = $model instanceof EstimateItemResource ? $frame['items']->get($model->estimate_item_id) : null;
        $modelEstimateId = match (true) {
            $model instanceof Estimate => $model->getKey(),
            $model instanceof EstimateItem, $model instanceof EstimateSection => $model->estimate_id,
            $model instanceof EstimateItemResource => $item?->estimate_id,
            default => null,
        };
        if (! is_numeric($modelEstimateId) || (int) $modelEstimateId !== $estimateId) {
            return false;
        }
        $type = match (true) {
            $model instanceof Estimate => 'estimate',
            $model instanceof EstimateItem => 'estimate_item',
            $model instanceof EstimateSection => 'estimate_section',
            $model instanceof EstimateItemResource => 'estimate_item_resource',
        };
        $frame['entities'][$type][(string) $model->getKey()] = (string) $model->getKey();
        if ($model instanceof Estimate) {
            return true;
        }
        $frame['entities']['estimate_summary'][(string) $estimateId] = (string) $estimateId;
        $sectionId = $model instanceof EstimateSection ? $model->getKey()
            : ($model instanceof EstimateItemResource ? $item?->estimate_section_id : $model->estimate_section_id);
        if (is_numeric($sectionId) && $frame['sections'] === null) {
            $frame['sections'] = EstimateSection::query()->where('estimate_id', $estimateId)->pluck('parent_section_id', 'id')->all();
        }
        $seen = [];
        while (is_numeric($sectionId) && array_key_exists((int) $sectionId, $frame['sections'] ?? []) && ! isset($seen[(int) $sectionId])) {
            $seen[(int) $sectionId] = true;
            $frame['entities']['estimate_section'][(string) $sectionId] = (string) $sectionId;
            $sectionId = $frame['sections'][(int) $sectionId];
        }

        return true;
    }
}
