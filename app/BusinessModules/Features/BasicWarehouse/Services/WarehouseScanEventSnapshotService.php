<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseIdentifier;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseScanEvent;

final class WarehouseScanEventSnapshotService
{
    private const SNAPSHOT_KEY = '_resolution_snapshot';

    public function __construct(private readonly WarehouseEntitySummaryService $entitySummaryService) {}

    public function withResolutionSnapshot(array $metadata, ?WarehouseIdentifier $identifier): array
    {
        unset($metadata[self::SNAPSHOT_KEY]);

        if ($identifier === null) {
            return $metadata;
        }

        $metadata[self::SNAPSHOT_KEY] = [
            'identifier' => $this->makeIdentifierPayload($identifier),
            'entity_summary' => $this->entitySummaryService->summarize(
                $identifier->entity_type,
                (int) $identifier->entity_id,
                (int) $identifier->organization_id
            ),
        ];

        return $metadata;
    }

    public function publicMetadata(WarehouseScanEvent $event): array
    {
        $metadata = $event->metadata ?? [];
        unset($metadata[self::SNAPSHOT_KEY]);

        return $metadata;
    }

    public function identifierPayload(WarehouseScanEvent $event): ?array
    {
        $snapshot = $this->snapshot($event);

        if (is_array($snapshot['identifier'] ?? null)) {
            return $snapshot['identifier'];
        }

        return $event->identifier ? $this->makeIdentifierPayload($event->identifier) : null;
    }

    public function entitySummary(WarehouseScanEvent $event): ?array
    {
        $snapshot = $this->snapshot($event);

        if (is_array($snapshot['entity_summary'] ?? null)) {
            return $snapshot['entity_summary'];
        }

        if ($event->logisticUnit) {
            return [
                'id' => $event->logisticUnit->id,
                'name' => $event->logisticUnit->name,
                'code' => $event->logisticUnit->code,
            ];
        }

        if ($event->identifier) {
            return [
                'id' => (int) $event->identifier->entity_id,
                'name' => $event->identifier->label ?: $event->identifier->code,
                'code' => $event->identifier->code,
            ];
        }

        if ($event->entity_type !== null && $event->entity_id !== null) {
            return $this->entitySummaryService->summarize(
                $event->entity_type,
                (int) $event->entity_id,
                (int) $event->organization_id
            );
        }

        return null;
    }

    private function snapshot(WarehouseScanEvent $event): array
    {
        if ($event->result !== WarehouseScanEvent::RESULT_RESOLVED) {
            return [];
        }

        $snapshot = ($event->metadata ?? [])[self::SNAPSHOT_KEY] ?? null;

        return is_array($snapshot) ? $snapshot : [];
    }

    private function makeIdentifierPayload(WarehouseIdentifier $identifier): array
    {
        return [
            'id' => $identifier->id,
            'code' => $identifier->code,
            'identifier_type' => $identifier->identifier_type,
            'entity_type' => $identifier->entity_type,
            'entity_id' => (int) $identifier->entity_id,
            'label' => $identifier->label,
            'status' => $identifier->status,
        ];
    }
}
