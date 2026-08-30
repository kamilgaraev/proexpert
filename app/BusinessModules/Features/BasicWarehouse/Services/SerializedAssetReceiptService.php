<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Core\AssetManagement\DTO\AssetPlacementData;
use App\BusinessModules\Core\AssetManagement\DTO\CreateOrganizationAssetData;
use App\BusinessModules\Core\AssetManagement\Enums\AssetAccountingMode;
use App\BusinessModules\Core\AssetManagement\Enums\AssetLifecycleStatus;
use App\BusinessModules\Core\AssetManagement\Models\OrganizationAsset;
use App\BusinessModules\Core\AssetManagement\Services\OrganizationAssetService;
use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\MachineryOperations\Services\MachineryAssetRegistryProjector;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class SerializedAssetReceiptService
{
    public function __construct(
        private OrganizationAssetService $organizationAssets,
        private MachineryAssetRegistryProjector $registryProjector,
    ) {}

    /**
     * @param  list<array{inventory_number: string, serial_number?: string|null, qr_code?: string|null}>  $instances
     * @return Collection<int, OrganizationAsset>
     */
    public function receive(
        int $organizationId,
        int $materialId,
        int $warehouseId,
        int $actorId,
        array $instances,
    ): Collection {
        return DB::transaction(function () use ($organizationId, $materialId, $warehouseId, $actorId, $instances): Collection {
            $material = Asset::query()
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->find($materialId);

            if ($material === null) {
                throw new DomainException(trans_message('basic_warehouse.serialized.material_not_found'));
            }

            if ($material->accounting_mode !== AssetAccountingMode::Serialized->value) {
                throw new DomainException(trans_message('basic_warehouse.serialized.quantitative_has_no_instances'));
            }

            WarehouseBalance::query()->firstOrCreate(
                [
                    'organization_id' => $organizationId,
                    'warehouse_id' => $warehouseId,
                    'material_id' => $materialId,
                ],
                [
                    'available_quantity' => 0,
                    'reserved_quantity' => 0,
                    'unit_price' => (float) ($material->default_price ?? 0),
                    'min_stock_level' => 0,
                    'max_stock_level' => 0,
                ],
            );

            $created = new Collection;

            foreach ($instances as $instance) {
                $inventoryNumber = trim($instance['inventory_number']);
                $qrCode = isset($instance['qr_code']) && trim((string) $instance['qr_code']) !== ''
                    ? trim((string) $instance['qr_code'])
                    : 'OA-'.$organizationId.'-'.Str::uuid()->toString();

                $asset = $this->organizationAssets->create(
                    $organizationId,
                    new CreateOrganizationAssetData(
                        name: $material->name.' — '.$inventoryNumber,
                        inventoryNumber: $inventoryNumber,
                        serialNumber: isset($instance['serial_number']) ? trim((string) $instance['serial_number']) ?: null : null,
                        qrCode: $qrCode,
                        accountingMode: AssetAccountingMode::Serialized,
                        materialId: (int) $material->id,
                        placement: new AssetPlacementData(warehouseId: $warehouseId),
                        actorId: $actorId,
                        metadata: ['warehouse_receipt' => ['material_id' => (int) $material->id]],
                    ),
                );
                $this->registryProjector->project($asset);
                $created->push($asset);
            }

            return $created;
        });
    }

    /** @param array{responsible_user_id: int, expected_return_at: string, reason?: string|null} $data */
    public function issue(int $organizationId, int $assetId, int $actorId, array $data): OrganizationAsset
    {
        return DB::transaction(function () use ($organizationId, $assetId, $actorId, $data): OrganizationAsset {
            $asset = $this->lockedAsset($organizationId, $assetId);

            if ($asset->accounting_mode !== AssetAccountingMode::Serialized) {
                throw new DomainException(trans_message('basic_warehouse.serialized.quantitative_has_no_custody'));
            }

            if ($asset->current_warehouse_id === null || $asset->responsible_user_id !== null) {
                throw new DomainException(trans_message('basic_warehouse.serialized.not_available_for_issue'));
            }

            return $this->organizationAssets->move(
                $asset,
                new AssetPlacementData(userId: (int) $data['responsible_user_id']),
                $actorId,
                'issued',
                array_filter([
                    'expected_return_at' => $data['expected_return_at'],
                    'reason' => $data['reason'] ?? null,
                ], static fn (mixed $value): bool => $value !== null),
            );
        });
    }

    /** @param array{warehouse_id: int, reason?: string|null} $data */
    public function returnToWarehouse(int $organizationId, int $assetId, int $actorId, array $data): OrganizationAsset
    {
        return DB::transaction(function () use ($organizationId, $assetId, $actorId, $data): OrganizationAsset {
            $asset = $this->lockedAsset($organizationId, $assetId);

            if ($asset->accounting_mode !== AssetAccountingMode::Serialized) {
                throw new DomainException(trans_message('basic_warehouse.serialized.quantitative_has_no_custody'));
            }

            if ($asset->responsible_user_id === null) {
                throw new DomainException(trans_message('basic_warehouse.serialized.not_issued'));
            }

            return $this->organizationAssets->move(
                $asset,
                new AssetPlacementData(warehouseId: (int) $data['warehouse_id']),
                $actorId,
                'returned',
                isset($data['reason']) ? ['reason' => $data['reason']] : null,
            );
        });
    }

    /** @param array{outcome: string, reason: string} $data */
    public function retire(int $organizationId, int $assetId, int $actorId, array $data): OrganizationAsset
    {
        return DB::transaction(function () use ($organizationId, $assetId, $actorId, $data): OrganizationAsset {
            $asset = $this->lockedAsset($organizationId, $assetId);

            if ($asset->accounting_mode !== AssetAccountingMode::Serialized) {
                throw new DomainException(trans_message('basic_warehouse.serialized.quantitative_has_no_custody'));
            }

            return $this->organizationAssets->retire(
                $asset,
                $actorId,
                AssetLifecycleStatus::from($data['outcome']),
                $data['reason'],
            );
        });
    }

    public function history(
        int $organizationId,
        int $assetId,
        int $perPage,
    ): LengthAwarePaginator {
        $asset = OrganizationAsset::query()
            ->forOrganization($organizationId)
            ->find($assetId);

        if ($asset === null) {
            throw new DomainException(trans_message('basic_warehouse.serialized.instance_not_found'));
        }

        return $asset->custodyEvents()
            ->with([
                'actor:id,name,email',
                'fromWarehouse:id,name,code',
                'toWarehouse:id,name,code',
                'fromProject:id,name',
                'toProject:id,name',
                'fromUser:id,name,email',
                'toUser:id,name,email',
            ])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(min(max($perPage, 1), 100));
    }

    /**
     * @param  array{warehouse_id?: int, project_id?: int, responsible_user_id?: int, material_id?: int, search?: string}  $filters
     */
    public function paginate(int $organizationId, array $filters, int $perPage): LengthAwarePaginator
    {
        return OrganizationAsset::query()
            ->forOrganization($organizationId)
            ->with(['operationProfile', 'currentWarehouse', 'currentProject', 'responsibleUser'])
            ->withCount('custodyEvents')
            ->when(isset($filters['warehouse_id']), fn ($query) => $query->where('current_warehouse_id', $filters['warehouse_id']))
            ->when(isset($filters['project_id']), fn ($query) => $query->where('current_project_id', $filters['project_id']))
            ->when(isset($filters['responsible_user_id']), fn ($query) => $query->where('responsible_user_id', $filters['responsible_user_id']))
            ->when(isset($filters['material_id']), fn ($query) => $query->where('material_id', $filters['material_id']))
            ->when(isset($filters['search']), function ($query) use ($filters): void {
                $operator = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
                $search = '%'.trim($filters['search']).'%';
                $query->where(fn ($nested) => $nested
                    ->where('name', $operator, $search)
                    ->orWhere('inventory_number', $operator, $search)
                    ->orWhere('serial_number', $operator, $search)
                    ->orWhere('qr_code', $operator, $search));
            })
            ->orderBy('inventory_number')
            ->paginate(min(max($perPage, 1), 100));
    }

    private function lockedAsset(int $organizationId, int $assetId): OrganizationAsset
    {
        $asset = OrganizationAsset::query()
            ->forOrganization($organizationId)
            ->lockForUpdate()
            ->find($assetId);

        if ($asset === null) {
            throw new DomainException(trans_message('basic_warehouse.serialized.instance_not_found'));
        }

        return $asset;
    }
}
