<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\DrillDown;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownTokenColumns;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models\InventoryDemandSnapshot;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models\InventoryReorderPolicyVersion;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models\InventoryRiskRow;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models\WarehouseInventoryEvent;
use App\Support\Reporting\EloquentOwnerDrillDown;
use App\Support\Reporting\OwnerReportTokenPayload;
use App\Support\Reporting\ReportSourceAccessPolicy;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final readonly class InventoryRiskDrillDownProvider implements ReportDrillDownProvider, ReportDrillDownTokenColumns
{
    public function drillDownTokenColumns(): array
    {
        return ['drill' => 'evidence_refs'];
    }

    public function __construct(
        private EloquentOwnerDrillDown $drillDown,
        private OwnerReportTokenPayload $tokens,
        private ReportSourceAccessPolicy $sourceAccess,
    ) {}

    public function drillDown(ReportExecutionContext $context, ReportSnapshotRef $snapshot, ReportDrillDownRequest $request): ReportDrillDownResult
    {
        $row = $this->authorizedRow($context, $snapshot, $request);
        $eventIds = $row->getAttribute('inventory_event_ids');
        if (! is_array($eventIds)) {
            throw new DomainException('Report drill-down pinned source identities are invalid.');
        }
        if ($eventIds === []) {
            return $this->planningEvidence($context, $snapshot, $request, $row);
        }

        return $this->drillDown->resolve(
            $context,
            $snapshot,
            $request,
            InventoryRiskRow::class,
            WarehouseInventoryEvent::class,
            'material_id',
            'material_id',
            [
                'warehouse_id',
                'project_id',
                'material_id',
                'source_movement_id',
                'source_version',
                'event_type',
                'on_hand_delta',
                'reserved_delta',
                'transfer_pair_key',
                'unit_dimension',
                'unit_code',
                'conversion_version',
                'occurred_at',
                'source_refs',
            ],
            [
                'warehouse_id',
                'project_id',
                'unit_dimension',
                'unit_code',
                'conversion_version',
            ],
            sourceResourceKind: 'warehouse',
            sourceResourceIdColumn: 'warehouse_id',
            requiresSensitive: true,
            rowSourceIdsColumn: 'inventory_event_ids',
            sourceCutoffColumn: 'occurred_at',
            rowDayColumn: 'balance_date',
            sourceOccurredAtColumn: 'occurred_at',
            allowEmptyPinnedSourceIds: true,
        );
    }

    private function authorizedRow(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportDrillDownRequest $request,
    ): InventoryRiskRow {
        if ($context->scope->canonicalIdentity() !== $snapshot->scope->canonicalIdentity()) {
            throw new DomainException('Report scope does not match snapshot scope.');
        }
        if (! $context->visibility->canView || ! $context->visibility->canViewSensitive) {
            throw new DomainException('Report drill-down is unavailable for the current access scope.');
        }

        $row = InventoryRiskRow::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id)
            ->where('row_key', $this->tokens->drillDownRowKey($request->token, $snapshot))
            ->firstOrFail();
        $warehouseId = $row->getAttribute('warehouse_id');
        $projectId = $row->getAttribute('project_id');
        if ((! is_int($warehouseId) && ! ctype_digit((string) $warehouseId))
            || ($projectId !== null && ! is_int($projectId) && ! ctype_digit((string) $projectId))
            || ! $this->sourceAccess->allows(
                $context->scope->resources,
                'warehouse',
                (int) $warehouseId,
                $projectId === null ? null : (int) $projectId,
                $context->scope->projectIds,
            )) {
            throw new DomainException('Report drill-down source is outside the authorized resource scope.');
        }

        return $row;
    }

    private function planningEvidence(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportDrillDownRequest $request,
        InventoryRiskRow $row,
    ): ReportDrillDownResult {
        $asOf = $snapshot->dimensions['as_of'] ?? null;
        if (! is_string($asOf) || trim($asOf) === '') {
            throw new DomainException('Report drill-down cutoff is unavailable.');
        }
        $offset = $request->cursor === null ? 0 : (int) $request->cursor;
        if ($request->cursor !== null && preg_match('/^[1-9][0-9]*$/D', $request->cursor) !== 1) {
            throw new DomainException('Report drill-down cursor is invalid.');
        }

        $records = collect();
        $demandId = $row->getAttribute('demand_snapshot_id');
        if (is_int($demandId) || ctype_digit((string) $demandId)) {
            $demand = $this->planningQuery(
                InventoryDemandSnapshot::query(),
                $context,
                $row,
                $asOf,
            )
                ->whereKey((int) $demandId)
                ->where('approved_at', '<=', $asOf)
                ->first();
            if ($demand instanceof InventoryDemandSnapshot) {
                $records->push($this->serializePlanningEvidence('demand_snapshot', $demand, $row, $asOf));
            }
        }

        $policyId = $row->getAttribute('reorder_policy_version_id');
        if (is_int($policyId) || ctype_digit((string) $policyId)) {
            $policy = $this->planningQuery(
                InventoryReorderPolicyVersion::query(),
                $context,
                $row,
                $asOf,
            )
                ->whereKey((int) $policyId)
                ->first();
            if ($policy instanceof InventoryReorderPolicyVersion) {
                $records->push($this->serializePlanningEvidence('reorder_policy', $policy, $row, $asOf));
            }
        }

        $page = $records->slice($offset, $request->limit)->values();
        $nextOffset = $offset + $page->count();

        return new ReportDrillDownResult(
            $page->all(),
            $nextOffset < $records->count() ? (string) $nextOffset : null,
            [],
        );
    }

    private function planningQuery(
        Builder $query,
        ReportExecutionContext $context,
        InventoryRiskRow $row,
        string $asOf,
    ): Builder {
        return $query
            ->where('organization_id', $context->scope->organizationId)
            ->where(static fn (Builder $scope): Builder => $scope
                ->whereNull('warehouse_id')
                ->orWhere('warehouse_id', $row->getAttribute('warehouse_id')))
            ->where(static fn (Builder $scope): Builder => $scope
                ->whereNull('project_id')
                ->orWhere('project_id', $row->getAttribute('project_id')))
            ->where(static fn (Builder $scope): Builder => $scope
                ->whereNull('material_id')
                ->orWhere('material_id', $row->getAttribute('material_id')))
            ->where('unit_dimension', $row->getAttribute('unit_dimension'))
            ->where('unit_code', $row->getAttribute('unit_code'))
            ->where('conversion_version', $row->getAttribute('conversion_version'))
            ->where('effective_from', '<=', $asOf)
            ->where(static fn (Builder $scope): Builder => $scope
                ->whereNull('effective_to')
                ->orWhere('effective_to', '>', $asOf));
    }

    private function serializePlanningEvidence(
        string $kind,
        Model $record,
        InventoryRiskRow $row,
        string $asOf,
    ): array {
        $serialized = $record->toArray();
        $balanceDate = $row->getAttribute('balance_date');

        return [
            'row_key' => 'planning_'.$kind.'_'.(string) $record->getKey(),
            'evidence_kind' => $kind,
            'warehouse_id' => $serialized['warehouse_id'] ?? null,
            'project_id' => $serialized['project_id'] ?? null,
            'material_id' => $serialized['material_id'] ?? null,
            'unit_dimension' => $serialized['unit_dimension'] ?? null,
            'unit_code' => $serialized['unit_code'] ?? null,
            'conversion_version' => $serialized['conversion_version'] ?? null,
            'effective_from' => $serialized['effective_from'] ?? null,
            'effective_to' => $serialized['effective_to'] ?? null,
            'source_version' => $serialized['source_version'] ?? null,
            'policy_version' => $serialized['policy_version'] ?? null,
            'approved_quantity' => $serialized['approved_quantity'] ?? null,
            'horizon_days' => $serialized['horizon_days'] ?? null,
            'approved_at' => $serialized['approved_at'] ?? null,
            'minimum_quantity' => $serialized['minimum_quantity'] ?? null,
            'reorder_point_quantity' => $serialized['reorder_point_quantity'] ?? null,
            'target_quantity' => $serialized['target_quantity'] ?? null,
            'safety_stock_quantity' => $serialized['safety_stock_quantity'] ?? null,
            'balance_date' => $balanceDate instanceof \DateTimeInterface
                ? $balanceDate->format('Y-m-d')
                : (string) $balanceDate,
            'as_of' => $asOf,
        ];
    }
}
