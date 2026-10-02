<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Observers\AssistantRagEntityObserver;
use App\BusinessModules\Features\AIAssistant\Services\AssistantExtendedDomainRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantIndexingState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CoreRagMutationBridge
{
    public static function definition(string $modelClass): ?array
    {
        $definition = AssistantRagEntityObserver::definitions()[$modelClass] ?? null;
        if ($definition === null || AssistantExtendedDomainRegistry::retrievalMode($definition[1]) === 'live_only') {
            return null;
        }

        return $definition;
    }

    public function changedRows(string $modelClass, Builder $rows, ?int $organizationId = null, ?int $projectId = null): void
    {
        $this->safely(function () use ($modelClass, $rows, $organizationId, $projectId): void {
            if (self::definition($modelClass) === null || app(AssistantIndexingState::class)->paused()) {
                return;
            }
            $key = $rows->getModel()->getKeyName();
            $query = clone $rows;
            $query->select($rows->getModel()->qualifyColumn($key));
            foreach ($query->lazyById(50, $rows->getModel()->qualifyColumn($key), $key) as $row) {
                $model = $rows->getModel()->newQuery()->whereKey($row->getKey())->first();
                [$rowOrganization, $rowProject] = $model === null ? [null, null] : $this->scope($model);
                $org = $organizationId ?? $rowOrganization;
                if ($org !== null) {
                    $this->queue($modelClass, $org, $rowProject ?? $projectId, $row->getKey());
                }
            }
        });
    }

    public function queue(string $modelClass, int $organizationId, ?int $projectId, string|int $id): void
    {
        $this->safely(function () use ($modelClass, $organizationId, $projectId, $id): void {
            $definition = self::definition($modelClass);
            if ($organizationId <= 0 || $definition === null || app(AssistantIndexingState::class)->paused()) {
                return;
            }
            [$source, $type] = $definition;
            $coordinator = app(RagIndexingCoordinator::class);
            $coordinator->queueEntity($organizationId, $projectId, $source, $type, $id);
            foreach (RagSource::query()->where('source_type', $source)->where('entity_type', $type)
                ->where('entity_id', (string) $id)->select(['organization_id', 'project_id'])->distinct()->cursor() as $previous) {
                if ((int) $previous->organization_id !== $organizationId || $previous->project_id !== $projectId) {
                    $coordinator->queueEntity((int) $previous->organization_id, $previous->project_id === null ? null : (int) $previous->project_id, $source, $type, $id);
                }
            }
        });
    }

    private function scope(Model $model, int $depth = 0): array
    {
        if ($depth > 5) {
            return [null, null];
        }
        $type = AssistantRagEntityObserver::definitions()[$model::class][1] ?? null;
        $columns = AssistantExtendedDomainRegistry::values('organizationColumns');
        $column = array_key_exists($type, $columns) ? $columns[$type] : 'organization_id';
        $organizationId = $column === null ? null : $model->getAttribute($column);
        $projectId = $model instanceof \App\Models\Project ? $model->getKey() : $model->getAttribute('project_id');
        $organizationId = is_numeric($organizationId) && (int) $organizationId > 0 ? (int) $organizationId : null;
        if ($organizationId !== null && is_numeric($projectId)) {
            return [(int) $organizationId, is_numeric($projectId) ? (int) $projectId : null];
        }
        foreach (AssistantExtendedDomainRegistry::values('parentColumns')[$type] ?? [] as $parentColumn => $parent) {
            $parentId = $model->getAttribute($parentColumn);
            $parentClass = AssistantDataAccessPolicy::entityDefinitions()[$parent['type']][1] ?? null;
            if ($parentId === null || $parentClass === null || ($parent['reference_only'] ?? false)) {
                continue;
            }
            $parentModel = $parentClass::query()->where($parent['key'] ?? 'id', $parentId)->first();
            if ($parentModel instanceof Model) {
                [$org, $project] = $this->scope($parentModel, $depth + 1);
                if ($org !== null && ($organizationId === null || $organizationId === $org)) {
                    return [$org, is_numeric($projectId) ? (int) $projectId : $project];
                }
            }
        }

        return [$organizationId, is_numeric($projectId) ? (int) $projectId : null];
    }

    private function safely(callable $operation): void
    {
        $transactional = DB::transactionLevel() > 0;
        try {
            DB::transaction($operation);
        } catch (Throwable $exception) {
            if ($transactional) {
                throw $exception;
            }
            try {
                Log::warning('ai_assistant.rag.bulk_queue_failed', ['exception_class' => $exception::class]);
            } catch (Throwable) {
            }
        }
    }
}
