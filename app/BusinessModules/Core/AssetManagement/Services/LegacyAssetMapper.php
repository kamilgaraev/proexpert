<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\AssetManagement\Services;

use App\BusinessModules\Core\AssetManagement\DTO\AssetPlacementData;
use App\BusinessModules\Core\AssetManagement\DTO\CreateOrganizationAssetData;
use App\BusinessModules\Core\AssetManagement\Enums\AssetLifecycleStatus;
use App\BusinessModules\Core\AssetManagement\Enums\AssetOperationalMode;
use App\BusinessModules\Core\AssetManagement\Enums\AssetTechnicalStatus;
use App\BusinessModules\Core\AssetManagement\Models\OrganizationAsset;
use App\Models\Project;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class LegacyAssetMapper
{
    /** @var list<string> */
    private const MACHINERY_OPERATION_TABLES = [
        'machinery_assignments',
        'machinery_shift_reports',
        'machinery_downtimes',
        'machinery_fuel_issues',
        'machinery_production_records',
        'machinery_maintenance_orders',
        'machinery_defects',
        'maintenance_inspections',
    ];

    public function __construct(private OrganizationAssetService $assets) {}

    /**
     * @return array{
     *     dry_run: bool,
     *     scanned: int,
     *     would_create: int,
     *     created: int,
     *     links_updated: int,
     *     already_linked: int,
     *     would_normalize_assignment_periods: int,
     *     assignment_periods_normalized: int,
     *     would_reconcile_placements: int,
     *     placements_reconciled: int,
     *     conflicts: int,
     *     conflict_records: list<array{source: string, reason: string}>
     * }
     */
    public function backfill(bool $dryRun): array
    {
        $report = [
            'dry_run' => $dryRun,
            'scanned' => 0,
            'would_create' => 0,
            'created' => 0,
            'links_updated' => 0,
            'already_linked' => 0,
            'would_normalize_assignment_periods' => 0,
            'assignment_periods_normalized' => 0,
            'would_reconcile_placements' => 0,
            'placements_reconciled' => 0,
            'conflicts' => 0,
            'conflict_records' => [],
        ];
        $duplicateMachineryInventories = $this->duplicateMachineryInventoryKeys();
        $duplicateWarehouseSerials = $this->duplicateWarehouseSerialKeys();

        DB::table('machinery_assets')->orderBy('id')->eachById(function (object $legacy) use (
            $dryRun,
            $duplicateMachineryInventories,
            &$report,
        ): void {
            $report['scanned']++;
            $inventoryNumber = $this->machineryInventoryNumber($legacy);
            $inventoryKey = $this->identityKey((int) $legacy->organization_id, $inventoryNumber);

            if (isset($duplicateMachineryInventories[$inventoryKey])) {
                $this->addConflict($report, $this->sourceKey('machinery_assets', (int) $legacy->id), 'duplicate_inventory_number');

                return;
            }

            $this->backfillMachinerySource($legacy, $inventoryNumber, $dryRun, $report);
        });

        $this->serializedWarehouseBalances()->orderBy('warehouse_balances.id')->eachById(
            function (object $legacy) use ($dryRun, $duplicateWarehouseSerials, &$report): void {
                $report['scanned']++;
                $source = $this->sourceKey('warehouse_balances', (int) $legacy->id);
                $serialKey = $this->identityKey((int) $legacy->organization_id, trim((string) $legacy->serial_number));

                if ((float) $legacy->total_quantity !== 1.0) {
                    $this->addConflict($report, $source, 'serialized_quantity_must_equal_one');

                    return;
                }

                if (isset($duplicateWarehouseSerials[$serialKey])) {
                    $this->addConflict($report, $source, 'duplicate_serial_number');

                    return;
                }

                $this->backfillWarehouseSource($legacy, $dryRun, $report);
            },
            column: 'warehouse_balances.id',
            alias: 'id',
        );

        $report['conflicts'] = count($report['conflict_records']);

        return $report;
    }

    /**
     * @return array{legacy: int, linked: int, missing: int, duplicates: int, field_conflicts: int}
     */
    public function reconcile(): array
    {
        $report = [
            'legacy' => 0,
            'linked' => 0,
            'missing' => 0,
            'duplicates' => 0,
            'field_conflicts' => 0,
        ];

        DB::table('machinery_assets')->orderBy('id')->eachById(function (object $legacy) use (&$report): void {
            $report['legacy']++;
            $matches = $this->canonicalMatches('machinery_assets', (int) $legacy->id, (int) $legacy->organization_id);

            if ($matches->isEmpty()) {
                $report['missing']++;

                return;
            }

            if ($matches->count() > 1) {
                $report['duplicates']++;
            } elseif ((int) ($legacy->organization_asset_id ?? 0) === (int) $matches->first()->id) {
                $report['linked']++;
            } else {
                $report['missing']++;
            }

            if ($matches->contains(fn (OrganizationAsset $asset): bool => ! $this->machineryFieldsMatch($legacy, $asset))) {
                $report['field_conflicts']++;
            }
        });

        $this->serializedWarehouseBalances()->orderBy('warehouse_balances.id')->eachById(
            function (object $legacy) use (&$report): void {
                $report['legacy']++;
                $matches = $this->canonicalMatches('warehouse_balances', (int) $legacy->id, (int) $legacy->organization_id);

                if ($matches->isEmpty()) {
                    $report['missing']++;

                    return;
                }

                if ($matches->count() > 1) {
                    $report['duplicates']++;
                } else {
                    $report['linked']++;
                }

                if ($matches->contains(fn (OrganizationAsset $asset): bool => ! $this->warehouseFieldsMatch($legacy, $asset))) {
                    $report['field_conflicts']++;
                }
            },
            column: 'warehouse_balances.id',
            alias: 'id',
        );

        return $report;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function backfillMachinerySource(object $legacy, string $inventoryNumber, bool $dryRun, array &$report): void
    {
        $source = $this->sourceKey('machinery_assets', (int) $legacy->id);
        $matches = $this->canonicalMatches('machinery_assets', (int) $legacy->id, (int) $legacy->organization_id);

        if ($matches->count() > 1) {
            $this->addConflict($report, $source, 'duplicate_legacy_source_mapping');

            return;
        }

        $canonical = $matches->first() ?? $this->matchingLinkedMachineryAsset($legacy);

        if ($canonical?->trashed()) {
            $this->addConflict($report, $source, 'canonical_mapping_deleted');

            return;
        }

        if ($this->hasMachineryShadowLinkConflict($legacy, $canonical)) {
            $this->addConflict($report, $source, 'shadow_link_mismatch');

            return;
        }

        if (! $this->prepareActiveAssignments($legacy, $canonical, $dryRun, $report)) {
            return;
        }

        if ($canonical === null) {
            if ($this->identityExists((int) $legacy->organization_id, $inventoryNumber, null)) {
                $this->addConflict($report, $source, 'inventory_number_already_used');

                return;
            }

            if ($dryRun) {
                $report['would_create']++;

                return;
            }

            $canonical = $this->assets->create(
                (int) $legacy->organization_id,
                new CreateOrganizationAssetData(
                    name: (string) $legacy->name,
                    inventoryNumber: $inventoryNumber,
                    ownershipType: (string) $legacy->ownership_type,
                    machineryId: $legacy->machinery_id !== null ? (int) $legacy->machinery_id : null,
                    placement: $legacy->current_project_id !== null
                        ? new AssetPlacementData(projectId: (int) $legacy->current_project_id)
                        : null,
                    metadata: [
                        ...(is_array($legacy->metadata) ? $legacy->metadata : (json_decode((string) ($legacy->metadata ?? ''), true) ?: [])),
                        'legacy_source' => ['table' => 'machinery_assets', 'id' => (int) $legacy->id],
                    ],
                    operationalMode: AssetOperationalMode::ShiftOperation,
                    tracksMeter: true,
                    tracksFuel: $legacy->fuel_type !== null || $legacy->fuel_consumption_rate !== null,
                    tracksProduction: true,
                    maintenanceEnabled: true,
                    meterUnit: 'hour',
                    operatingCostPerHour: (float) $legacy->operating_cost_per_hour,
                    fuelType: $legacy->fuel_type !== null ? (string) $legacy->fuel_type : null,
                    fuelConsumptionRate: $legacy->fuel_consumption_rate !== null ? (float) $legacy->fuel_consumption_rate : null,
                    meterValue: (float) $legacy->meter_hours,
                ),
            );
            $canonical->update([
                'lifecycle_status' => $this->machineryLifecycleStatus($legacy),
                'technical_status' => $this->machineryTechnicalStatus($legacy),
            ]);
            $report['created']++;
        }

        if ($dryRun) {
            return;
        }

        $updated = DB::table('machinery_assets')
            ->where('id', $legacy->id)
            ->whereNull('organization_asset_id')
            ->update(['organization_asset_id' => $canonical->id]);
        $report['links_updated'] += $updated;

        foreach (self::MACHINERY_OPERATION_TABLES as $table) {
            $report['links_updated'] += DB::table($table)
                ->where('asset_id', $legacy->id)
                ->whereNull('organization_asset_id')
                ->update(['organization_asset_id' => $canonical->id]);
        }

        if ($updated === 0 && (int) ($legacy->organization_asset_id ?? 0) === (int) $canonical->id) {
            $report['already_linked']++;
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function backfillWarehouseSource(object $legacy, bool $dryRun, array &$report): void
    {
        $source = $this->sourceKey('warehouse_balances', (int) $legacy->id);
        $matches = $this->canonicalMatches('warehouse_balances', (int) $legacy->id, (int) $legacy->organization_id);

        if ($matches->count() > 1) {
            $this->addConflict($report, $source, 'duplicate_legacy_source_mapping');

            return;
        }

        $canonical = $matches->first();
        $inventoryNumber = 'WHB-'.$legacy->id;
        $serialNumber = trim((string) $legacy->serial_number);

        if ($canonical?->trashed()) {
            $this->addConflict($report, $source, 'canonical_mapping_deleted');

            return;
        }

        if ($this->hasWarehouseMovementLinkConflict($legacy, $canonical)) {
            $this->addConflict($report, $source, 'shadow_link_mismatch');

            return;
        }

        if ($canonical === null) {
            if ($this->identityExists((int) $legacy->organization_id, $inventoryNumber, $serialNumber)) {
                $this->addConflict($report, $source, 'warehouse_identity_already_used');

                return;
            }

            if ($dryRun) {
                $report['would_create']++;

                return;
            }

            $canonical = $this->assets->create(
                (int) $legacy->organization_id,
                new CreateOrganizationAssetData(
                    name: (string) $legacy->material_name,
                    inventoryNumber: $inventoryNumber,
                    serialNumber: $serialNumber,
                    materialId: (int) $legacy->material_id,
                    placement: new AssetPlacementData(warehouseId: (int) $legacy->warehouse_id),
                    metadata: ['legacy_source' => ['table' => 'warehouse_balances', 'id' => (int) $legacy->id]],
                ),
            );
            $report['created']++;
        } elseif ($dryRun) {
            return;
        }

        $report['links_updated'] += DB::table('warehouse_movements')
            ->where('organization_id', $legacy->organization_id)
            ->where('material_id', $legacy->material_id)
            ->where('metadata->warehouse_balance_id', (int) $legacy->id)
            ->whereNull('organization_asset_id')
            ->update(['organization_asset_id' => $canonical->id]);

        if ($matches->isNotEmpty()) {
            $report['already_linked']++;
        }
    }

    private function serializedWarehouseBalances(): Builder
    {
        return DB::table('warehouse_balances')
            ->join('materials', 'materials.id', '=', 'warehouse_balances.material_id')
            ->select([
                'warehouse_balances.id',
                'warehouse_balances.organization_id',
                'warehouse_balances.warehouse_id',
                'warehouse_balances.material_id',
                'warehouse_balances.serial_number',
                'materials.name as material_name',
            ])
            ->selectRaw('(warehouse_balances.available_quantity + warehouse_balances.reserved_quantity) AS total_quantity')
            ->whereNotNull('warehouse_balances.serial_number')
            ->where('warehouse_balances.serial_number', '<>', '');
    }

    /** @return Collection<int, OrganizationAsset> */
    private function canonicalMatches(string $table, int $id, int $organizationId): Collection
    {
        return OrganizationAsset::query()
            ->withTrashed()
            ->forOrganization($organizationId)
            ->where('metadata->legacy_source->table', $table)
            ->where('metadata->legacy_source->id', $id)
            ->get();
    }

    private function identityExists(int $organizationId, string $inventoryNumber, ?string $serialNumber): bool
    {
        return OrganizationAsset::query()
            ->withTrashed()
            ->forOrganization($organizationId)
            ->where(function ($query) use ($inventoryNumber, $serialNumber): void {
                $query->where('inventory_number', $inventoryNumber);

                if ($serialNumber !== null && $serialNumber !== '') {
                    $query->orWhere('serial_number', $serialNumber);
                }
            })
            ->exists();
    }

    /** @return array<string, true> */
    private function duplicateMachineryInventoryKeys(): array
    {
        return DB::table('machinery_assets')
            ->selectRaw("organization_id, COALESCE(NULLIF(TRIM(inventory_number), ''), TRIM(asset_code)) AS effective_inventory")
            ->groupByRaw("organization_id, COALESCE(NULLIF(TRIM(inventory_number), ''), TRIM(asset_code))")
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                $this->identityKey((int) $row->organization_id, (string) $row->effective_inventory) => true,
            ])
            ->all();
    }

    /** @return array<string, true> */
    private function duplicateWarehouseSerialKeys(): array
    {
        return DB::table('warehouse_balances')
            ->selectRaw('organization_id, TRIM(serial_number) AS normalized_serial')
            ->whereNotNull('serial_number')
            ->whereRaw("TRIM(serial_number) <> ''")
            ->groupByRaw('organization_id, TRIM(serial_number)')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                $this->identityKey((int) $row->organization_id, (string) $row->normalized_serial) => true,
            ])
            ->all();
    }

    private function machineryInventoryNumber(object $legacy): string
    {
        $inventoryNumber = trim((string) ($legacy->inventory_number ?? ''));

        return $inventoryNumber !== '' ? $inventoryNumber : trim((string) $legacy->asset_code);
    }

    private function machineryFieldsMatch(object $legacy, OrganizationAsset $asset): bool
    {
        return ! $asset->trashed()
            && (int) $asset->organization_id === (int) $legacy->organization_id
            && $asset->name === (string) $legacy->name
            && $asset->inventory_number === $this->machineryInventoryNumber($legacy)
            && $asset->ownership_type === (string) $legacy->ownership_type
            && (int) ($asset->machinery_id ?? 0) === (int) ($legacy->machinery_id ?? 0);
    }

    private function warehouseFieldsMatch(object $legacy, OrganizationAsset $asset): bool
    {
        return ! $asset->trashed()
            && (int) $asset->organization_id === (int) $legacy->organization_id
            && $asset->name === (string) $legacy->material_name
            && $asset->inventory_number === 'WHB-'.$legacy->id
            && $asset->serial_number === trim((string) $legacy->serial_number)
            && (int) $asset->material_id === (int) $legacy->material_id
            && (int) $asset->current_warehouse_id === (int) $legacy->warehouse_id;
    }

    private function hasMachineryShadowLinkConflict(object $legacy, ?OrganizationAsset $canonical): bool
    {
        $canonicalId = $canonical?->id;
        $legacyLink = $legacy->organization_asset_id ?? null;

        if ($legacyLink !== null && (int) $legacyLink !== (int) ($canonicalId ?? 0)) {
            return true;
        }

        foreach (self::MACHINERY_OPERATION_TABLES as $table) {
            $query = DB::table($table)
                ->where('asset_id', $legacy->id)
                ->whereNotNull('organization_asset_id');

            if ($canonicalId === null ? $query->exists() : $query->where('organization_asset_id', '<>', $canonicalId)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function matchingLinkedMachineryAsset(object $legacy): ?OrganizationAsset
    {
        if ($legacy->organization_asset_id === null) {
            return null;
        }

        $canonical = OrganizationAsset::query()
            ->withTrashed()
            ->forOrganization((int) $legacy->organization_id)
            ->find((int) $legacy->organization_asset_id);

        return $canonical !== null && $this->machineryFieldsMatch($legacy, $canonical)
            ? $canonical
            : null;
    }

    private function hasWarehouseMovementLinkConflict(object $legacy, ?OrganizationAsset $canonical): bool
    {
        $query = DB::table('warehouse_movements')
            ->where('organization_id', $legacy->organization_id)
            ->where('material_id', $legacy->material_id)
            ->where('metadata->warehouse_balance_id', (int) $legacy->id)
            ->whereNotNull('organization_asset_id');

        return $canonical === null
            ? $query->exists()
            : $query->where('organization_asset_id', '<>', $canonical->id)->exists();
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function prepareActiveAssignments(
        object $legacy,
        ?OrganizationAsset $canonical,
        bool $dryRun,
        array &$report,
    ): bool {
        $assignments = DB::table('machinery_assignments')
            ->where('asset_id', $legacy->id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->orderBy('planned_start_at')
            ->orderBy('id')
            ->get();
        $normalizations = [];

        foreach ($assignments->zip($assignments->skip(1)) as $pair) {
            [$earlier, $later] = $pair;
            if ($later === null) {
                continue;
            }

            $earlierStart = Carbon::parse($earlier->planned_start_at);
            $laterStart = Carbon::parse($later->planned_start_at);
            $earlierEnd = $earlier->planned_end_at !== null ? Carbon::parse($earlier->planned_end_at) : null;
            if ($earlierEnd !== null && $earlierEnd->lessThanOrEqualTo($laterStart)) {
                continue;
            }
            if ($earlierStart->equalTo($laterStart)) {
                $this->addConflict(
                    $report,
                    $this->sourceKey('machinery_assets', (int) $legacy->id),
                    'ambiguous_active_assignments',
                );

                return false;
            }

            $normalizations[] = ['id' => (int) $earlier->id, 'planned_end_at' => $laterStart];
        }

        $observedAt = now();
        $effective = $assignments
            ->filter(static function (object $assignment) use ($observedAt): bool {
                $start = Carbon::parse($assignment->planned_start_at);
                $end = $assignment->planned_end_at !== null ? Carbon::parse($assignment->planned_end_at) : null;

                return $start->lessThanOrEqualTo($observedAt) && ($end === null || $end->isAfter($observedAt));
            })
            ->sortByDesc('planned_start_at')
            ->first();
        if ($effective === null) {
            $this->applyAssignmentPeriodNormalizations($normalizations, $dryRun, $report);

            return true;
        }

        $organizationId = (int) $legacy->organization_id;
        $projectId = (int) $effective->project_id;
        if (
            (int) $effective->organization_id !== $organizationId
            || ! Project::query()->accessibleByOrganization($organizationId)->whereKey($projectId)->exists()
        ) {
            $this->addConflict(
                $report,
                $this->sourceKey('machinery_assets', (int) $legacy->id),
                'assignment_scope_mismatch',
            );

            return false;
        }

        $legacyNeedsUpdate = (int) ($legacy->current_project_id ?? 0) !== $projectId;
        $canonicalNeedsUpdate = $canonical !== null && (int) ($canonical->current_project_id ?? 0) !== $projectId;
        $this->applyAssignmentPeriodNormalizations($normalizations, $dryRun, $report);
        if (! $legacyNeedsUpdate && ! $canonicalNeedsUpdate) {
            return true;
        }
        if ($dryRun) {
            $report['would_reconcile_placements']++;

            return true;
        }

        if ($legacyNeedsUpdate) {
            DB::table('machinery_assets')->where('id', $legacy->id)->update(['current_project_id' => $projectId]);
            $legacy->current_project_id = $projectId;
        }
        if ($canonicalNeedsUpdate) {
            $fromWarehouseId = $canonical->current_warehouse_id;
            $fromProjectId = $canonical->current_project_id;
            $fromUserId = $canonical->responsible_user_id;
            $canonical->update([
                'current_warehouse_id' => null,
                'current_project_id' => $projectId,
                'responsible_user_id' => null,
            ]);
            DB::table('asset_custody_events')->insert([
                'organization_id' => $organizationId,
                'organization_asset_id' => $canonical->id,
                'actor_user_id' => null,
                'event_type' => 'cutover_reconciled',
                'from_warehouse_id' => $fromWarehouseId,
                'from_project_id' => $fromProjectId,
                'from_user_id' => $fromUserId,
                'to_warehouse_id' => null,
                'to_project_id' => $projectId,
                'to_user_id' => null,
                'metadata' => json_encode(['assignment_id' => (int) $effective->id], JSON_THROW_ON_ERROR),
                'occurred_at' => now(),
                'created_at' => now(),
            ]);
        }
        $report['placements_reconciled']++;

        return true;
    }

    /**
     * @param  list<array{id: int, planned_end_at: Carbon}>  $normalizations
     * @param  array<string, mixed>  $report
     */
    private function applyAssignmentPeriodNormalizations(array $normalizations, bool $dryRun, array &$report): void
    {
        if ($dryRun) {
            $report['would_normalize_assignment_periods'] += count($normalizations);

            return;
        }

        foreach ($normalizations as $normalization) {
            DB::table('machinery_assignments')
                ->where('id', $normalization['id'])
                ->update(['planned_end_at' => $normalization['planned_end_at']]);
            $report['assignment_periods_normalized']++;
        }
    }

    private function machineryLifecycleStatus(object $legacy): AssetLifecycleStatus
    {
        return $legacy->archived_at !== null || $legacy->status === 'archived'
            ? AssetLifecycleStatus::Retired
            : AssetLifecycleStatus::Active;
    }

    private function machineryTechnicalStatus(object $legacy): AssetTechnicalStatus
    {
        return match ($legacy->status) {
            'maintenance' => AssetTechnicalStatus::Maintenance,
            'unavailable' => AssetTechnicalStatus::Unavailable,
            default => AssetTechnicalStatus::Serviceable,
        };
    }

    private function identityKey(int $organizationId, string $identifier): string
    {
        return $organizationId.'|'.$identifier;
    }

    private function sourceKey(string $table, int $id): string
    {
        return $table.':'.$id;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function addConflict(array &$report, string $source, string $reason): void
    {
        $report['conflict_records'][] = ['source' => $source, 'reason' => $reason];
    }
}
