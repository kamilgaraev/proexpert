<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantSalesBusinessMetadata;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SalesRagMutationBridge
{
    public static function definition(string $modelClass): ?array
    {
        foreach (AssistantSalesBusinessMetadata::observerDefinitions() as $model => $definition) {
            if ($model === $modelClass) {
                if ((AssistantSalesBusinessMetadata::retrievalCoverageDefinitions()[$definition[1]]['mode'] ?? null) === 'unavailable') { return null; }
                return CoreRagMutationBridge::definition($modelClass);
            }
        }

        return null;
    }

    public static function changedRows(string $modelClass, Builder $rows, ?int $organizationId = null, ?int $projectId = null): void
    {
        if (self::definition($modelClass) === null) {
            return;
        }
        self::safely(function () use ($modelClass, $rows, $organizationId, $projectId): void {
            if ($rows->getModel()::class !== $modelClass) {
                return;
            }
            $selected = clone $rows;
            $selected->reorder();
            app(CoreRagMutationBridge::class)->changedRows($modelClass, $selected, $organizationId, $projectId);
        });
    }

    public static function queue(string $modelClass, int $organizationId, ?int $projectId, string|int $id): void
    {
        if (self::definition($modelClass) === null) {
            return;
        }
        self::safely(function () use ($modelClass, $organizationId, $projectId, $id): void {
            app(CoreRagMutationBridge::class)->queue($modelClass, $organizationId, $projectId, $id);
        });
    }

    private static function safely(callable $operation): void
    {
        $transactional = DB::transactionLevel() > 0;
        try {
            $operation();
        } catch (Throwable $exception) {
            if ($transactional) {
                throw $exception;
            }
            try {
                Log::warning('ai_assistant.rag.sales_queue_failed', ['exception_class' => $exception::class]);
            } catch (Throwable) {
            }
        }
    }
}
