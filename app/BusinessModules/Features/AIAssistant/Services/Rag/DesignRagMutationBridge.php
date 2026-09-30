<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use App\BusinessModules\Features\AIAssistant\Services\AssistantIndexingState;
use App\BusinessModules\Features\DesignManagement\Models\DesignCompositionRevision;
use App\BusinessModules\Features\DesignManagement\Models\DesignDocumentSheet;
use App\BusinessModules\Features\DesignManagement\Models\DesignIfcModelElement;
use App\BusinessModules\Features\DesignManagement\Models\DesignIfcUploadSession;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DesignRagMutationBridge
{
    private const MODELS = [DesignCompositionRevision::class, DesignDocumentSheet::class, DesignIfcModelElement::class, DesignIfcUploadSession::class, DesignPackage::class];

    public static function definition(string $modelClass): ?array
    {
        return in_array($modelClass, self::MODELS, true) ? CoreRagMutationBridge::definition($modelClass) : null;
    }

    public function changedRows(string $modelClass, Builder $rows): void
    {
        $this->safely(function () use ($modelClass, $rows): void {
            if (self::definition($modelClass) === null || app(AssistantIndexingState::class)->paused() || $rows->getModel()::class !== $modelClass) {
                return;
            }
            $model = $rows->getModel();
            $key = $model->getKeyName();
            $query = clone $rows;
            $query->select([$model->qualifyColumn($key), $model->qualifyColumn('organization_id'), $model->qualifyColumn('project_id')]);
            foreach ($query->lazyById(50, $model->qualifyColumn($key), $key) as $row) {
                $organizationId = $row->getAttribute('organization_id');
                $projectId = $row->getAttribute('project_id');
                if (! is_numeric($organizationId) || (int) $organizationId <= 0 || $row->getKey() === null) {
                    continue;
                }
                app(CoreRagMutationBridge::class)->queue($modelClass, (int) $organizationId, is_numeric($projectId) ? (int) $projectId : null, $row->getKey());
            }
        });
    }

    public function ifcRows(array $rows): void
    {
        $this->safely(function () use ($rows): void {
            if (count($rows) > 500 || app(AssistantIndexingState::class)->paused()) {
                return;
            }
            $versions = [];
            foreach ($rows as $row) {
                if (! is_numeric($row['version_id'] ?? null) || ! is_int($row['express_id'] ?? null)) {
                    continue;
                }
                $versions[(int) $row['version_id']][] = $row['express_id'];
            }
            foreach ($versions as $versionId => $expressIds) {
                $this->changedRows(DesignIfcModelElement::class, DesignIfcModelElement::query()->where('version_id', $versionId)->whereIn('express_id', array_values(array_unique($expressIds))));
            }
        });
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
                Log::warning('ai_assistant.rag.design_bulk_queue_failed', ['exception_class' => $exception::class]);
            } catch (Throwable) {
            }
        }
    }
}
