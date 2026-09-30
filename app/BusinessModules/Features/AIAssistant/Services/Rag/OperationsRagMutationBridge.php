<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use App\BusinessModules\Features\AIAssistant\Services\AssistantIndexingState;
use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantOperationsBusinessMetadata;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class OperationsRagMutationBridge
{
    public function changed(string $table, int $organizationId, int|string $id): void
    {
        if (app(AssistantIndexingState::class)->paused() || $organizationId < 1) {
            return;
        }
        $records = AssistantOperationsBusinessMetadata::recordDefinitions();
        foreach (['machinery_assets' => 'machinery_asset', 'machinery_assignments' => 'machinery_assignment'] as $legacyTable => $legacyType) {
            $records[$legacyType] = ['table' => $legacyTable, 'source' => 'machinery'];
        }
        foreach ($records as $type => $record) {
            if ($record['table'] !== $table) {
                continue;
            }
            $transactional = DB::transactionLevel() > 0;
            try {
                DB::transaction(static fn () => app(RagIndexingCoordinator::class)->queueEntity($organizationId, null, $record['source'], $type, $id));
            } catch (Throwable $exception) {
                if ($transactional) {
                    throw $exception;
                }
                try {
                    Log::warning('assistant.operations.index_pending_failed', ['organization_id' => $organizationId, 'entity_type' => $type, 'exception_class' => $exception::class]);
                } catch (Throwable) {
                }
            }
            return;
        }
    }

    public function changedRows(string $table, int $organizationId, Builder $rows): void
    {
        if (app(AssistantIndexingState::class)->paused()) {
            return;
        }
        foreach (AssistantOperationsBusinessMetadata::recordDefinitions() as $record) {
            if ($record['table'] !== $table) {
                continue;
            }
            $key = $record['key'];
            $query = clone $rows;
            if ($record['organization_column'] !== null) {
                $query->where($table.'.'.$record['organization_column'], $organizationId);
            }
            foreach ($query->select($table.'.'.$key)->lazyById(50, $table.'.'.$key, $key) as $row) {
                $this->changed($table, $organizationId, $row->{$key});
            }
            return;
        }
    }

    public function snapshot(string $table, string $rowTable, int $organizationId, int|string $snapshotId): void
    {
        $this->changed($table, $organizationId, $snapshotId);
        $this->changedRows($rowTable, $organizationId, DB::table($rowTable)->where('snapshot_id', $snapshotId));
    }
}
