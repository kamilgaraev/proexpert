<?php

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Contracts\WarehouseReportDataProvider;
use App\BusinessModules\Features\BasicWarehouse\DTOs\WarehouseBalanceAggregateDTO;
use App\BusinessModules\Features\BasicWarehouse\Exceptions\WarehouseOperationIdempotencyConflictException;
use App\BusinessModules\Features\BasicWarehouse\Models\AssetReservation;
use App\BusinessModules\Features\BasicWarehouse\Models\AutoReorderRule;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseIdentifier;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseItemGallery;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseProjectAllocation;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Services\CanonicalWarehouseReportingIdentity;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Services\WarehouseInventoryEventRecorder;
use App\BusinessModules\Features\Procurement\Enums\PurchaseReceiptStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceiptLine;
use App\BusinessModules\Features\WorkforceManagement\Contracts\WorkforcePersonNameProvider;
use App\Models\Material;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Logging\LoggingService;
use Carbon\Carbon;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

use function trans_message;

/**
 * Сервис управления складом
 * Реализует WarehouseReportDataProvider для интеграции с модулями отчетов
 */
class WarehouseService implements WarehouseReportDataProvider
{
    protected LoggingService $logging;

    public function __construct(
        LoggingService $logging,
        private readonly WarehouseInventoryEventRecorder $inventoryEventRecorder,
        private readonly CanonicalWarehouseReportingIdentity $reportingIdentity,
        private readonly ReservationQuantityService $reservationQuantityService,
        private readonly ProjectAllocationAvailabilityService $allocationAvailabilityService,
        private readonly WorkforcePersonNameProvider $personNameProvider,
        private readonly WarehousePersonIdentityResolver $personIdentityResolver,
    ) {
        $this->logging = $logging;
    }

    /**
     * Создать центральный склад для организации
     */
    public function createCentralWarehouse(int $organizationId, array $data = []): OrganizationWarehouse
    {
        $organization = Organization::findOrFail($organizationId);

        $warehouse = OrganizationWarehouse::create([
            'organization_id' => $organizationId,
            'name' => $data['name'] ?? 'Центральный склад',
            'code' => $data['code'] ?? 'CENTRAL',
            'address' => $data['address'] ?? null,
            'description' => $data['description'] ?? 'Основной склад организации',
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => true,
            'is_active' => true,
            'settings' => $data['settings'] ?? [],
        ]);

        $this->logging->business('warehouse.central.created', [
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
        ]);

        return $warehouse;
    }

    /**
     * Получить или создать центральный склад организации
     */
    public function getOrCreateCentralWarehouse(int $organizationId): OrganizationWarehouse
    {
        $warehouse = OrganizationWarehouse::where('organization_id', $organizationId)
            ->where('is_main', true)
            ->first();

        if (! $warehouse) {
            $warehouse = $this->createCentralWarehouse($organizationId);
        }

        return $warehouse;
    }

    /**
     * Получить все склады организации
     */
    public function getWarehouses(int $organizationId, bool $activeOnly = true): \Illuminate\Database\Eloquent\Collection
    {
        $query = OrganizationWarehouse::query()
            ->with('project')
            ->where('organization_id', $organizationId);

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        $warehouses = $query->orderBy('is_main', 'desc')
            ->orderBy('name')
            ->get();

        return $this->applyReadableCustodyWarehouseNames($organizationId, $warehouses);
    }

    public function custodyWarehouseName(
        int $organizationId,
        Project $project,
        User $responsibleUser,
        DateTimeInterface $date
    ): string {
        return $this->buildCustodyWarehouseName(
            $organizationId,
            (string) $project->name,
            (int) $responsibleUser->id,
            $date
        );
    }

    public function withReadableWarehouseName(OrganizationWarehouse $warehouse): OrganizationWarehouse
    {
        if ($warehouse->warehouse_type !== OrganizationWarehouse::TYPE_CUSTODY) {
            return $warehouse;
        }

        $warehouse->loadMissing('project');
        $warehouse->setAttribute('name', $this->buildCustodyWarehouseName(
            (int) $warehouse->organization_id,
            (string) ($warehouse->project?->name
                ?? trans_message('basic_warehouse.custody.project_name_missing')),
            $warehouse->responsible_user_id !== null ? (int) $warehouse->responsible_user_id : null,
            $warehouse->created_at ?? now()
        ));
        $warehouse->syncOriginalAttribute('name');

        return $warehouse;
    }

    private function buildCustodyWarehouseName(
        int $organizationId,
        string $projectName,
        ?int $responsibleUserId,
        DateTimeInterface $date
    ): string {
        return trans_message('basic_warehouse.custody.warehouse_name', [
            'project' => $projectName,
            'user' => $responsibleUserId !== null
                ? ($this->personNameProvider->employeeNameAt($organizationId, $responsibleUserId, $date)
                    ?? trans_message('basic_warehouse.custody.person_name_missing'))
                : trans_message('basic_warehouse.custody.person_name_missing'),
        ]);
    }

    public function applyReadableCustodyWarehouseNames(
        int $organizationId,
        \Illuminate\Database\Eloquent\Collection $warehouses
    ): \Illuminate\Database\Eloquent\Collection {
        $custodyWarehouses = $warehouses->filter(
            static fn (OrganizationWarehouse $warehouse): bool => $warehouse->warehouse_type === OrganizationWarehouse::TYPE_CUSTODY
                && $warehouse->responsible_user_id !== null
        );
        $references = $custodyWarehouses->mapWithKeys(
            static fn (OrganizationWarehouse $warehouse): array => [
                $warehouse->id => [
                    'user_id' => (int) $warehouse->responsible_user_id,
                    'date' => $warehouse->created_at ?? now(),
                ],
            ]
        )->all();
        $personNames = $this->personNameProvider->employeeNamesAt($organizationId, $references);

        $custodyWarehouses->each(
            static function (OrganizationWarehouse $warehouse) use ($personNames): void {
                $warehouse->setAttribute('name', trans_message('basic_warehouse.custody.warehouse_name', [
                    'project' => $warehouse->project?->name
                        ?? trans_message('basic_warehouse.custody.project_name_missing'),
                    'user' => $personNames[$warehouse->id]
                        ?? trans_message('basic_warehouse.custody.person_name_missing'),
                ]));
                $warehouse->syncOriginalAttribute('name');
            }
        );

        return $warehouses;
    }

    /**
     * Приход актива на склад
     */
    /**
     * Приход актива на склад (Партионный учет)
     */
    public function receiveAsset(
        int $organizationId,
        int $warehouseId,
        int $materialId,
        float $quantity,
        float $price,
        array $metadata = [],
        bool $recordInventoryEvent = true
    ): array {
        DB::beginTransaction();
        try {
            $this->lockWarehouses($organizationId, [$warehouseId]);
            $operation = ($metadata['is_transfer'] ?? false) === true ? 'transfer_in' : 'receipt';
            $metadata = $this->prepareIdempotencyMetadata($operation, [
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
                'quantity' => $quantity,
                'price' => $price,
                'metadata' => $metadata,
            ], $metadata);
            $existingMovement = $this->findIdempotentMovement(
                $organizationId,
                $operation,
                (string) ($metadata['idempotency_key'] ?? ''),
                (string) ($metadata['idempotency_fingerprint'] ?? ''),
            );
            if ($existingMovement !== null) {
                $balance = WarehouseBalance::query()
                    ->where('organization_id', $organizationId)
                    ->where('warehouse_id', $warehouseId)
                    ->where('material_id', $materialId)
                    ->first();

                DB::commit();

                return [
                    'balance' => $balance,
                    'movement' => $existingMovement,
                    'new_quantity' => (float) WarehouseBalance::query()
                        ->where('organization_id', $organizationId)
                        ->where('warehouse_id', $warehouseId)
                        ->where('material_id', $materialId)
                        ->sum('available_quantity'),
                ];
            }
            $metadata = $this->reportingMetadata($organizationId, $warehouseId, $materialId, $metadata);
            if (
                ($metadata['is_transfer'] ?? false) === true
                && (int) ($metadata['from_warehouse_id'] ?? 0) < 1
            ) {
                throw new \DomainException(
                    trans_message('warehouse_basic.validation.transfer_source_required')
                );
            }
            // Ищем существующую партию с такой же ценой и параметрами
            // (Стратегия: смешиваем партии только если они абсолютно идентичны по цене и срокам)
            $query = WarehouseBalance::where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouseId)
                ->where('material_id', $materialId)
                ->where('unit_price', $price);

            if (isset($metadata['batch_number'])) {
                $query->where('batch_number', $metadata['batch_number']);
            } else {
                $query->whereNull('batch_number');
            }

            if (isset($metadata['expiry_date'])) {
                $query->where('expiry_date', $metadata['expiry_date']);
            } else {
                $query->whereNull('expiry_date');
            }

            // Если включено адресное хранение, то разделяем и по местам
            if (isset($metadata['location_code'])) {
                $query->where('location_code', $metadata['location_code']);
            }

            if (isset($metadata['cell_id'])) {
                $query->where('cell_id', $metadata['cell_id']);
            }

            $balance = $query->lockForUpdate()->first();

            if ($balance) {
                // Если партия найдена - увеличиваем количество
                $balance->available_quantity += $quantity;
                $balance->last_movement_at = now();
                $balance->save();
            } else {
                // Если нет - создаем новую партию
                $balance = WarehouseBalance::create([
                    'organization_id' => $organizationId,
                    'warehouse_id' => $warehouseId,
                    'material_id' => $materialId,
                    'available_quantity' => $quantity,
                    'unit_price' => $price,
                    'batch_number' => $metadata['batch_number'] ?? null,
                    'expiry_date' => $metadata['expiry_date'] ?? null,
                    'cell_id' => $metadata['cell_id'] ?? null,
                    'location_code' => $metadata['location_code'] ?? null,
                    'created_at' => now(), // Важно для FIFO
                    'last_movement_at' => now(),
                ]);
            }

            // Создаем запись движения
            $movement = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'cell_id' => $metadata['cell_id'] ?? null,
                'material_id' => $materialId,
                'movement_type' => ($metadata['is_transfer'] ?? false) === true ? 'transfer_in' : 'receipt',
                'quantity' => $quantity,
                'price' => $price,
                'project_id' => $metadata['project_id'] ?? null,
                'project_material_delivery_id' => $metadata['project_material_delivery_id'] ?? null,
                'user_id' => $metadata['user_id'] ?? null,
                'related_user_id' => $metadata['related_user_id'] ?? null,
                'document_number' => $metadata['document_number'] ?? null,
                'reason' => $metadata['reason'] ?? null,
                'operation_category' => $metadata['operation_category'] ?? null,
                'from_warehouse_id' => ($metadata['is_transfer'] ?? false) === true
                    ? (int) ($metadata['from_warehouse_id'] ?? 0)
                    : null,
                'metadata' => $metadata,
                'movement_date' => now(),
            ]);
            $eventType = is_string($metadata['reporting_event_type'] ?? null)
                ? $metadata['reporting_event_type']
                : (($metadata['is_transfer'] ?? false) === true
                    ? 'transfer_in'
                    : (($metadata['operation_category'] ?? null) === WarehouseMovement::CATEGORY_RESPONSIBLE_RETURN
                        ? 'return'
                        : 'receipt'));
            if ($recordInventoryEvent) {
                $this->inventoryEventRecorder->record(
                    $movement,
                    $eventType,
                    in_array($eventType, ['transfer_in', 'transfer_out'], true)
                        ? (string) ($metadata['transfer_pair_key'] ?? '')
                        : null,
                );
            }

            $this->logging->business('warehouse.asset.received', [
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
                'quantity' => $quantity,
                'price' => $price,
                'batch_id' => $balance->id,
                'movement_id' => $movement->id,
            ]);

            DB::commit();

            // Очистка кэша
            $this->clearWarehouseCache($organizationId);

            return [
                'balance' => $balance,
                'movement' => $movement,
                'new_quantity' => (float) $balance->available_quantity,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Списание актива со склада (FIFO стратегия)
     */
    public function writeOffAsset(
        int $organizationId,
        int $warehouseId,
        int $materialId,
        float $quantity,
        array $metadata = []
    ): array {
        DB::beginTransaction();
        try {
            $this->lockWarehouses($organizationId, [$warehouseId]);
            $metadata = $this->prepareIdempotencyMetadata('write_off', [
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
                'quantity' => $quantity,
                'metadata' => $metadata,
            ], $metadata);
            $existingMovement = $this->findIdempotentMovement(
                $organizationId,
                'write_off',
                (string) ($metadata['idempotency_key'] ?? ''),
                (string) ($metadata['idempotency_fingerprint'] ?? ''),
            );
            if ($existingMovement !== null) {
                DB::commit();

                return [
                    'movement' => $existingMovement,
                    'write_off_details' => $existingMovement->metadata['batches_source'] ?? [],
                    'remaining_total_quantity' => (float) WarehouseBalance::query()
                        ->where('organization_id', $organizationId)
                        ->where('warehouse_id', $warehouseId)
                        ->where('material_id', $materialId)
                        ->sum('available_quantity'),
                ];
            }
            $metadata = $this->reportingMetadata($organizationId, $warehouseId, $materialId, $metadata);
            // Получаем все партии с доступным количеством, сортируем по дате создания (FIFO)
            // (или по сроку годности FEFO, если есть)
            $batchesQuery = WarehouseBalance::where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouseId)
                ->where('material_id', $materialId)
                ->where('available_quantity', '>', 0);

            if (isset($metadata['cell_id'])) {
                $batchesQuery->where('cell_id', $metadata['cell_id']);
            }

            $batches = $batchesQuery
                ->orderByRaw('CASE WHEN expiry_date IS NOT NULL THEN expiry_date ELSE created_at END ASC')
                ->lockForUpdate()
                ->get();

            $totalAvailable = $batches->sum('available_quantity');

            if ($totalAvailable < $quantity) {
                throw new \InvalidArgumentException(
                    trans_message('warehouse_basic.validation.insufficient_stock', [
                        'available' => (float) $totalAvailable,
                        'requested' => (float) $quantity,
                    ])
                );
            }

            $remainingToWriteOff = $quantity;
            $writeOffDetails = []; // Для истории, с каких партий списали
            $totalCost = 0;

            foreach ($batches as $batch) {
                if ($remainingToWriteOff <= 0) {
                    break;
                }

                $takeFromBatch = min($batch->available_quantity, $remainingToWriteOff);

                $batch->decreaseQuantity($takeFromBatch);

                $remainingToWriteOff -= $takeFromBatch;
                $writeOffDetails[] = [
                    'balance_id' => $batch->id,
                    'quantity' => $takeFromBatch,
                    'unit_price' => $batch->unit_price,
                    'batch_number' => $batch->batch_number,
                    'cell_id' => $batch->cell_id,
                    'location_code' => $batch->location_code,
                ];

                $totalCost += ($takeFromBatch * $batch->unit_price);
            }

            // Рассчитываем среднюю цену списания для движения
            $avgWriteOffPrice = $quantity > 0 ? $totalCost / $quantity : 0;

            // Создаем запись движения
            $movement = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'cell_id' => $metadata['cell_id'] ?? null,
                'material_id' => $materialId,
                'movement_type' => 'write_off',
                'quantity' => $quantity,
                'price' => $avgWriteOffPrice, // Фиксируем среднюю цену списания
                'project_id' => $metadata['project_id'] ?? null,
                'project_material_delivery_id' => $metadata['project_material_delivery_id'] ?? null,
                'user_id' => $metadata['user_id'] ?? null,
                'related_user_id' => $metadata['related_user_id'] ?? null,
                'document_number' => $metadata['document_number'] ?? null,
                'reason' => $metadata['reason'] ?? null,
                'operation_category' => $metadata['operation_category'] ?? null,
                'metadata' => array_merge($metadata, ['batches_source' => $writeOffDetails]),
                'movement_date' => now(),
            ]);
            $eventType = is_string($metadata['reporting_event_type'] ?? null)
                ? $metadata['reporting_event_type']
                : 'issue';
            $this->inventoryEventRecorder->record(
                $movement,
                $eventType,
                $eventType === 'transfer_out' ? (string) ($metadata['transfer_pair_key'] ?? '') : null,
            );

            $this->logging->business('warehouse.asset.written_off', [
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
                'quantity' => $quantity,
                'avg_price' => $avgWriteOffPrice,
                'batches_count' => count($writeOffDetails),
                'movement_id' => $movement->id,
            ]);

            DB::commit();

            // Очистка кэша
            $this->clearWarehouseCache($organizationId);

            return [
                'movement' => $movement,
                'write_off_details' => $writeOffDetails,
                'remaining_total_quantity' => $totalAvailable - $quantity,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Перемещение актива между складами (с поддержкой FIFO)
     */
    public function transferAsset(
        int $organizationId,
        int $fromWarehouseId,
        int $toWarehouseId,
        int $materialId,
        float $quantity,
        array $metadata = [],
        bool $sourceUnlocatedOnly = false,
        bool $recordInventoryEvent = true
    ): array {
        DB::beginTransaction();
        try {
            $this->lockWarehouses($organizationId, [$fromWarehouseId, $toWarehouseId]);
            $metadata = $this->prepareIdempotencyMetadata('transfer_out', [
                'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id' => $toWarehouseId,
                'material_id' => $materialId,
                'quantity' => $quantity,
                'metadata' => $metadata,
            ], $metadata);
            $existingMovementOut = $this->findIdempotentMovement(
                $organizationId,
                'transfer_out',
                (string) ($metadata['idempotency_key'] ?? ''),
                (string) ($metadata['idempotency_fingerprint'] ?? ''),
            );
            if ($existingMovementOut !== null) {
                $existingMovementIn = WarehouseMovement::query()
                    ->where('organization_id', $organizationId)
                    ->where('movement_type', 'transfer_in')
                    ->where('metadata->idempotency_key', $metadata['idempotency_key'])
                    ->firstOrFail();

                DB::commit();

                return [
                    'movement_out' => $existingMovementOut,
                    'movement_in' => $existingMovementIn,
                    'avg_price' => (float) $existingMovementOut->price,
                    'source_details' => $existingMovementOut->metadata['source_batches'] ?? [],
                ];
            }
            $transferPairKey = (string) ($metadata['transfer_pair_key'] ?? \Illuminate\Support\Str::ulid());
            $metadata['transfer_pair_key'] = $transferPairKey;
            $sourceMetadata = $this->reportingMetadata(
                $organizationId,
                $fromWarehouseId,
                $materialId,
                $metadata,
            );
            // 1. Списываем с исходного склада по FIFO
            $sourceBatchesQuery = WarehouseBalance::where('organization_id', $organizationId)
                ->where('warehouse_id', $fromWarehouseId)
                ->where('material_id', $materialId)
                ->where('available_quantity', '>', 0);

            if ($sourceUnlocatedOnly) {
                $sourceBatchesQuery->whereNull('cell_id');
            } elseif (isset($metadata['from_cell_id'])) {
                $sourceBatchesQuery->where('cell_id', $metadata['from_cell_id']);
            }

            if (isset($metadata['from_batch_number'])) {
                $sourceBatchesQuery->where('batch_number', $metadata['from_batch_number']);
            }

            $sourceBatches = $sourceBatchesQuery
                ->orderByRaw('CASE WHEN expiry_date IS NOT NULL THEN expiry_date ELSE created_at END ASC')
                ->lockForUpdate()
                ->get();

            $totalAvailable = $sourceBatches->sum('available_quantity');

            if ($totalAvailable < $quantity) {
                $messageKey = $sourceUnlocatedOnly
                    ? 'warehouse_basic.validation.insufficient_unlocated_stock'
                    : 'warehouse_basic.validation.insufficient_transfer_stock';

                throw new \InvalidArgumentException(
                    trans_message($messageKey, [
                        'available' => (float) $totalAvailable,
                        'requested' => (float) $quantity,
                    ])
                );
            }

            $remainingToTransfer = $quantity;
            $transferCost = 0;
            $sourceBatchDetails = [];

            foreach ($sourceBatches as $batch) {
                if ($remainingToTransfer <= 0) {
                    break;
                }

                $takeFromBatch = min($batch->available_quantity, $remainingToTransfer);

                $batch->decreaseQuantity($takeFromBatch);

                $remainingToTransfer -= $takeFromBatch;
                $transferCost += ($takeFromBatch * $batch->unit_price);

                $sourceBatchDetails[] = [
                    'source_batch_id' => $batch->id,
                    'quantity' => $takeFromBatch,
                    'unit_price' => $batch->unit_price,
                    'batch_number' => $batch->batch_number,
                    'cell_id' => $batch->cell_id,
                    'location_code' => $batch->location_code,
                ];
            }

            // Средняя цена перемещаемой партии
            $transferPrice = $quantity > 0 ? $transferCost / $quantity : 0;

            // 2. Приходуем на целевой склад (как одну партию с усредненной ценой)
            // (Можно было бы переносить партиями, но это сильно усложнит логику, обычно при перемещении принимают по учетной стоимости)
            $targetReason = ($metadata['operation_category'] ?? null) === WarehouseMovement::CATEGORY_PLACEMENT
                ? ($metadata['reason'] ?? trans_message('warehouse_basic.placement_default_reason'))
                : trans_message('warehouse_basic.transfer_from_warehouse_reason', [
                    'warehouse' => $fromWarehouseId,
                ]);
            $targetResult = $this->receiveAsset(
                $organizationId,
                $toWarehouseId,
                $materialId,
                $quantity,
                $transferPrice,
                array_merge($metadata, [
                    'reason' => $targetReason,
                    'is_transfer' => true,
                    'transfer_pair_key' => $transferPairKey,
                    'from_warehouse_id' => $fromWarehouseId,
                ]),
                $recordInventoryEvent
            );

            $movementIn = $targetResult['movement'];

            // 3. Создаем движение расхода с исходного склада
            $movementOut = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $fromWarehouseId,
                'cell_id' => $metadata['from_cell_id'] ?? null,
                'material_id' => $materialId,
                'movement_type' => 'transfer_out',
                'quantity' => $quantity,
                'price' => $transferPrice,
                'to_warehouse_id' => $toWarehouseId,
                'project_id' => $metadata['project_id'] ?? null,
                'project_material_delivery_id' => $metadata['project_material_delivery_id'] ?? null,
                'user_id' => $metadata['user_id'] ?? null,
                'related_user_id' => $metadata['related_user_id'] ?? null,
                'document_number' => $metadata['document_number'] ?? null,
                'reason' => $metadata['reason'] ?? null,
                'operation_category' => $metadata['operation_category'] ?? null,
                'metadata' => array_merge($sourceMetadata, ['source_batches' => $sourceBatchDetails]),
                'movement_date' => now(),
            ]);
            if ($recordInventoryEvent) {
                $this->inventoryEventRecorder->record($movementOut, 'transfer_out', $transferPairKey);
            }

            $this->logging->business('warehouse.asset.transferred', [
                'organization_id' => $organizationId,
                'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id' => $toWarehouseId,
                'material_id' => $materialId,
                'quantity' => $quantity,
                'avg_price' => $transferPrice,
                'movement_out_id' => $movementOut->id,
                'movement_in_id' => $movementIn->id,
            ]);

            DB::commit();

            // Очистка кэша
            $this->clearWarehouseCache($organizationId);

            return [
                'movement_out' => $movementOut,
                'movement_in' => $movementIn,
                'avg_price' => $transferPrice,
                'source_details' => $sourceBatchDetails,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function placeUnlocatedAsset(
        int $organizationId,
        int $warehouseId,
        int $materialId,
        float $quantity,
        array $metadata = []
    ): array {
        return $this->transferAsset(
            $organizationId,
            $warehouseId,
            $warehouseId,
            $materialId,
            $quantity,
            array_merge($metadata, [
                'operation_category' => WarehouseMovement::CATEGORY_PLACEMENT,
                'reason' => $metadata['reason'] ?? trans_message('warehouse_basic.placement_default_reason'),
            ]),
            true,
            false
        );
    }

    private function reportingMetadata(
        int $organizationId,
        int $warehouseId,
        int $materialId,
        array $metadata,
    ): array {
        $material = Material::query()
            ->with('measurementUnit')
            ->where('organization_id', $organizationId)
            ->findOrFail($materialId);
        $unit = $material->measurementUnit;
        $unitIdentity = $unit === null ? 'unknown' : 'measurement-unit:'.$unit->getKey();
        $warehouse = OrganizationWarehouse::query()
            ->where('organization_id', $organizationId)
            ->findOrFail($warehouseId);
        $inventoryProjectId = $warehouse->project_id === null ? null : (int) $warehouse->project_id;
        $hasMovement = WarehouseMovement::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->where('material_id', $materialId)
            ->exists();
        $currentOnHand = WarehouseBalance::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->where('material_id', $materialId)
            ->get()
            ->sum(static fn (WarehouseBalance $balance): float => (float) $balance->available_quantity
                + (float) $balance->reserved_quantity);
        $openingBasis = ! $hasMovement && $currentOnHand === 0.0 ? 'verified_zero' : null;

        return $this->reportingIdentity->merge([
            'reporting_source_version' => 1,
            'unit_dimension' => $unitIdentity,
            'unit_code' => $unit?->short_name ?? 'unknown',
            'unit_conversion_version' => $unit === null ? 'unproven' : $unitIdentity.':identity-v1',
            'reporting_inventory_project_id' => $inventoryProjectId,
            'currency' => 'RUB',
            'currency_source' => 'warehouse_movement.price',
            'reporting_opening_basis' => $openingBasis,
        ], $metadata);
    }

    private function lockWarehouses(int $organizationId, array $warehouseIds): void
    {
        OrganizationWarehouse::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', array_values(array_unique($warehouseIds)))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function prepareIdempotencyMetadata(string $operation, array $payload, array $metadata): array
    {
        $key = trim((string) ($metadata['idempotency_key'] ?? ''));
        if ($key === '') {
            return $metadata;
        }

        $metadata['idempotency_key'] = $key;
        $metadata['idempotency_fingerprint'] = WarehouseOperationIdempotency::fingerprint($operation, $payload);

        return $metadata;
    }

    private function findIdempotentMovement(
        int $organizationId,
        string $movementType,
        string $key,
        string $fingerprint,
    ): ?WarehouseMovement {
        if ($key === '') {
            return null;
        }

        $movement = WarehouseMovement::query()
            ->where('organization_id', $organizationId)
            ->where('movement_type', $movementType)
            ->where('metadata->idempotency_key', $key)
            ->first();
        if ($movement === null) {
            return null;
        }

        if (($movement->metadata['idempotency_fingerprint'] ?? null) !== $fingerprint) {
            throw new WarehouseOperationIdempotencyConflictException(
                trans_message('warehouse_basic.idempotency_conflict')
            );
        }

        return $movement;
    }

    /**
     * Получить остаток актива на складе (Агрегированный)
     */
    public function getAssetBalance(int $organizationId, int $warehouseId, int $materialId): ?WarehouseBalanceAggregateDTO
    {
        $batches = WarehouseBalance::where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->where('material_id', $materialId)
            ->get();

        if ($batches->isEmpty()) {
            return null;
        }

        $totalQty = $batches->sum('available_quantity');
        $totalReserved = $batches->sum('reserved_quantity');

        $totalValue = $batches->sum(fn ($b) => $b->available_quantity * $b->unit_price);
        $avgPrice = $totalQty > 0 ? $totalValue / $totalQty : 0;
        $allocatedByMaterial = $this->allocationAvailabilityService->outstandingByMaterial(
            $organizationId,
            $warehouseId,
            [$materialId],
        );

        return new WarehouseBalanceAggregateDTO(
            materialId: $materialId,
            warehouseId: $warehouseId,
            availableQuantity: (float) $totalQty,
            reservedQuantity: (float) $totalReserved,
            allocatedQuantity: (float) ($allocatedByMaterial->get($materialId) ?? 0),
            averagePrice: (float) $avgPrice,
            totalValue: (float) $totalValue,
            lastMovementAt: $batches->max('last_movement_at')?->toDateTimeString(),
            material: $batches->first()->material ?? null,
            warehouse: $batches->first()->warehouse ?? null
        );
    }

    /**
     * Получить все остатки на складе (Агрегированные)
     *
     * @return Collection<WarehouseBalanceAggregateDTO>
     */
    public function getWarehouseStock(int $organizationId, int $warehouseId, array $filters = []): Collection
    {
        $query = WarehouseBalance::where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->where('available_quantity', '>', 0)
            ->with(['material', 'warehouse', 'cell.zone']);

        // Фильтры
        if (isset($filters['asset_type'])) {
            $query->whereHas('material', function ($q) use ($filters) {
                $driver = $q->getConnection()->getDriverName();
                if ($driver === 'pgsql') {
                    $q->whereRaw("additional_properties->>'asset_type' = ?", [$filters['asset_type']]);
                } else {
                    $q->whereRaw("JSON_EXTRACT(additional_properties, '$.asset_type') = ?", [$filters['asset_type']]);
                }
            });
        }

        if (isset($filters['category'])) {
            $query->whereHas('material', function ($q) use ($filters) {
                $q->where('category', $filters['category']);
            });
        }

        $this->applyStockLocationFilters($query, $organizationId, $filters);

        if (isset($filters['low_stock']) && $filters['low_stock']) {
            $query->lowStock();
        }

        $allBatches = $query->get();

        // Группируем по материалам
        $grouped = $allBatches->groupBy('material_id');
        $allocatedByMaterial = $this->allocationAvailabilityService->outstandingByMaterial(
            $organizationId,
            $warehouseId,
            $grouped->keys()->map(static fn ($materialId): int => (int) $materialId)->all(),
        );

        $aggregatedCollection = new Collection;

        foreach ($grouped as $materialId => $batches) {
            $totalQty = $batches->sum('available_quantity');
            $totalReserved = $batches->sum('reserved_quantity');

            $totalValue = $batches->sum(fn ($b) => $b->available_quantity * $b->unit_price);
            $avgPrice = $totalQty > 0 ? $totalValue / $totalQty : 0;

            $dto = new WarehouseBalanceAggregateDTO(
                materialId: $materialId,
                warehouseId: $warehouseId,
                availableQuantity: (float) $totalQty,
                reservedQuantity: (float) $totalReserved,
                allocatedQuantity: (float) ($allocatedByMaterial->get($materialId) ?? 0),
                averagePrice: (float) $avgPrice,
                totalValue: (float) $totalValue,
                lastMovementAt: $batches->max('last_movement_at')?->toDateTimeString(),
                material: $batches->first()->material,
                warehouse: $batches->first()->warehouse
            );

            $aggregatedCollection->push($dto);
        }

        return $aggregatedCollection;
    }

    /**
     * Очистить кэш склада
     */
    protected function clearWarehouseCache(int $organizationId): void
    {
        Cache::forget("warehouse_stock_{$organizationId}");
        Cache::forget("warehouse_low_stock_{$organizationId}");
    }

    // ===== Реализация WarehouseReportDataProvider =====

    /**
     * Получить данные по остаткам на складе для отчетов (Агрегированные)
     */
    public function getStockData(int $organizationId, array $filters = []): array
    {
        $query = $this->buildStockQuery($organizationId, $filters);

        $allBatches = $query->get();
        $this->applyReadableCustodyWarehouseNames(
            $organizationId,
            new \Illuminate\Database\Eloquent\Collection(
                $allBatches->pluck('warehouse')->filter()->unique('id')->values()->all()
            )
        );
        $photoMap = WarehouseItemGallery::with('photos')
            ->where('organization_id', $organizationId)
            ->whereIn('warehouse_id', $allBatches->pluck('warehouse_id')->unique())
            ->whereIn('material_id', $allBatches->pluck('material_id')->unique())
            ->get()
            ->mapWithKeys(fn (WarehouseItemGallery $gallery) => [
                $gallery->warehouse_id.':'.$gallery->material_id => $gallery->photo_gallery,
            ])
            ->all();

        $receiptPhotoMap = WarehouseMovement::with('photos')
            ->where('organization_id', $organizationId)
            ->where('movement_type', WarehouseMovement::TYPE_RECEIPT)
            ->whereIn('warehouse_id', $allBatches->pluck('warehouse_id')->unique())
            ->whereIn('material_id', $allBatches->pluck('material_id')->unique())
            ->whereHas('photos')
            ->orderByDesc('movement_date')
            ->get()
            ->groupBy(fn (WarehouseMovement $movement) => $movement->warehouse_id.':'.$movement->material_id)
            ->map(static fn ($movements) => $movements
                ->flatMap(static fn (WarehouseMovement $movement) => $movement->photo_gallery)
                ->take(4)
                ->values()
                ->all())
            ->all();
        $receiptDocumentMap = $this->purchaseReceiptDocumentMap($organizationId, $allBatches);

        $identifierMap = WarehouseIdentifier::query()
            ->where('organization_id', $organizationId)
            ->where('entity_type', 'asset')
            ->where('identifier_type', WarehouseIdentifier::TYPE_QR)
            ->where('status', WarehouseIdentifier::STATUS_ACTIVE)
            ->whereIn('entity_id', $allBatches->pluck('material_id')->unique())
            ->orderByDesc('is_primary')
            ->orderByDesc('updated_at')
            ->get()
            ->groupBy('entity_id')
            ->map(static fn ($identifiers) => $identifiers->first())
            ->all();

        $grouped = $allBatches->groupBy(
            static fn (WarehouseBalance $batch): string => $batch->warehouse_id.':'.$batch->material_id
        );
        $stockAllocations = WarehouseProjectAllocation::query()
            ->where('organization_id', $organizationId)
            ->whereIn('warehouse_id', $allBatches->pluck('warehouse_id')->unique())
            ->whereIn('material_id', $allBatches->pluck('material_id')->unique())
            ->with('project:id,name')
            ->get();
        $outstandingByAllocation = $this->allocationAvailabilityService->outstandingForAllocations(
            $organizationId,
            $stockAllocations,
        );
        $allocationsByStockPosition = $stockAllocations->groupBy(
            static fn (WarehouseProjectAllocation $allocation): string => $allocation->warehouse_id.':'.$allocation->material_id,
        );

        $resultData = [];

        foreach ($grouped as $stockPositionKey => $batches) {
            $totalQty = $batches->sum('available_quantity');
            $totalReserved = $batches->sum('reserved_quantity');
            $totalPhysicalQuantity = $totalQty + $totalReserved;
            $totalValue = $batches->sum(
                fn ($batch) => ($batch->available_quantity + $batch->reserved_quantity) * $batch->unit_price
            );
            $avgPrice = $totalPhysicalQuantity > 0 ? $totalValue / $totalPhysicalQuantity : 0;

            $cellBatches = $batches->filter(
                static fn (WarehouseBalance $batch): bool => $batch->cell_id !== null
            );
            $unlocatedBatches = $batches->filter(
                static fn (WarehouseBalance $batch): bool => $batch->cell_id === null
                    && (! is_string($batch->location_code) || trim($batch->location_code) === '')
            );
            $unlocatedQuantity = (float) $unlocatedBatches->sum(
                static fn (WarehouseBalance $batch): float => (float) $batch->available_quantity
                    + (float) $batch->reserved_quantity
            );
            $addressedQuantity = (float) $totalQty + (float) $totalReserved - $unlocatedQuantity;
            $cellIds = $cellBatches->pluck('cell_id')->unique()->values();
            $allBatchesInSingleCell = $cellIds->count() === 1 && $cellBatches->count() === $batches->count();
            $cells = $cellBatches
                ->groupBy('cell_id')
                ->map(static function ($batchesInCell): ?array {
                    $cell = $batchesInCell->first()?->cell;

                    if ($cell === null) {
                        return null;
                    }

                    return [
                        'id' => $cell->id,
                        'code' => $cell->code,
                        'name' => $cell->name,
                        'full_address' => $cell->full_address,
                        'available_quantity' => (float) $batchesInCell->sum('available_quantity'),
                        'reserved_quantity' => (float) $batchesInCell->sum('reserved_quantity'),
                        'zone' => $cell->zone ? [
                            'id' => $cell->zone->id,
                            'code' => $cell->zone->code,
                            'name' => $cell->zone->name,
                        ] : null,
                    ];
                })
                ->filter()
                ->values();

            $first = $batches->first();
            $qrCode = $identifierMap[$first->material_id]->code ?? sprintf('AST-%d-%06d', $organizationId, $first->material_id);
            $galleryKey = (string) $stockPositionKey;
            $balancePhotos = $photoMap[$galleryKey] ?? [];
            $receiptPhotos = $receiptPhotoMap[$galleryKey] ?? [];

            $item = [
                'warehouse_id' => $first->warehouse_id,
                'warehouse_name' => $first->warehouse->name,
                'material_id' => $first->material_id,
                'material_name' => $first->material->name,
                'material_code' => $first->material->code,
                'asset_type' => $first->material->additional_properties['asset_type'] ?? 'material',
                'category' => $first->material->category,
                'measurement_unit' => $first->material->measurementUnit->short_name
                    ?? $first->material->measurementUnit->name
                    ?? null,
                'available_quantity' => (float) $totalQty,
                'reserved_quantity' => (float) $totalReserved,
                'total_quantity' => (float) $totalPhysicalQuantity,
                'average_price' => (float) $avgPrice,
                'total_value' => $totalValue, // Точная сумма цен всех партий
                'min_stock_level' => (float) $first->min_stock_level,
                'max_stock_level' => (float) $first->max_stock_level,
                'is_low_stock' => $first->min_stock_level > 0 && $totalQty <= $first->min_stock_level,
                'location_code' => $batches->pluck('location_code')->filter()->unique()->implode(', '),
                'addressed_quantity' => $addressedQuantity,
                'unlocated_quantity' => $unlocatedQuantity,
                'has_unlocated_quantity' => $unlocatedQuantity > 0,
                'cell_id' => $allBatchesInSingleCell ? $cellIds->first() : null,
                'cell_ids' => $cellIds->all(),
                'cells' => $cells->all(),
                'cell' => $allBatchesInSingleCell ? $cells->first() : null,
                'storage_address' => $batches->pluck('cell.full_address')->filter()->unique()->implode(', ')
                    ?: $batches->pluck('location_code')->filter()->unique()->implode(', '),
                'last_movement_at' => $batches->max('last_movement_at')?->toDateTimeString(),
                'photo_gallery' => $balancePhotos !== [] ? $balancePhotos : $receiptPhotos,
                'receipt_photo_gallery' => $receiptPhotos,
                'receipt_documents' => $receiptDocumentMap[$galleryKey] ?? [],
                'asset_photo_gallery' => $first->material->photo_gallery,
                'qr_code' => $qrCode,
                'qr_code_image_url' => $this->makeQrDataUri($qrCode),
            ];

            $allocations = $allocationsByStockPosition->get($galleryKey, collect());
            $projectAllocations = $allocations
                ->map(function (WarehouseProjectAllocation $allocation) use ($outstandingByAllocation): array {
                    return [
                        'project_id' => $allocation->project_id,
                        'project_name' => $allocation->project->name,
                        'allocated_quantity' => (float) ($outstandingByAllocation->get($allocation->id) ?? 0),
                        'planned_quantity' => (float) $allocation->allocated_quantity,
                    ];
                })
                ->filter(static fn (array $allocation): bool => $allocation['allocated_quantity'] > 0)
                ->values();

            $item['project_allocations'] = $projectAllocations->all();
            $item['allocated_total'] = (float) $projectAllocations->sum('allocated_quantity');
            $item['unallocated_quantity'] = max(0.0, (float) $totalQty - $item['allocated_total']);
            $item['available_for_allocation'] = $item['unallocated_quantity'];

            $resultData[] = $item;
        }

        return $resultData;
    }

    public function getPaginatedStockData(
        int $organizationId,
        array $filters,
        int $page,
        int $perPage,
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $queryFilters = $filters;
        unset($queryFilters['low_stock']);
        $baseQuery = $this->buildStockQuery($organizationId, $queryFilters);
        $baseQuery->setEagerLoads([]);

        $positionQuery = (clone $baseQuery)
            ->selectRaw('warehouse_id, material_id')
            ->selectRaw('SUM(available_quantity) AS available_quantity')
            ->selectRaw('SUM(reserved_quantity) AS reserved_quantity')
            ->selectRaw('MAX(min_stock_level) AS min_stock_level')
            ->selectRaw('SUM((available_quantity + reserved_quantity) * unit_price) AS total_value')
            ->groupBy('warehouse_id', 'material_id');
        if (! empty($filters['low_stock'])) {
            $positionQuery
                ->havingRaw('MAX(min_stock_level) > 0')
                ->havingRaw('SUM(available_quantity) <= MAX(min_stock_level)');
        }

        $summary = DB::query()
            ->fromSub(clone $positionQuery, 'stock_positions')
            ->selectRaw('COUNT(*) AS total_items')
            ->selectRaw(
                'SUM(CASE WHEN min_stock_level > 0 AND available_quantity <= min_stock_level THEN 1 ELSE 0 END) AS low_stock_count'
            )
            ->selectRaw('COALESCE(SUM(total_value), 0) AS total_value')
            ->first();

        $total = (int) ($summary->total_items ?? 0);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $positionPairs = (clone $positionQuery)
            ->orderBy('warehouse_id')
            ->orderBy('material_id')
            ->forPage($page, $perPage)
            ->get()
            ->map(static fn ($position): array => [
                'warehouse_id' => (int) $position->warehouse_id,
                'material_id' => (int) $position->material_id,
            ])
            ->values();

        $items = $positionPairs->isEmpty()
            ? []
            : $this->getStockData($organizationId, [
                ...$queryFilters,
                'position_pairs' => $positionPairs->all(),
            ]);
        $positionOrder = $positionPairs
            ->mapWithKeys(static fn (array $pair, int $index): array => [
                $pair['warehouse_id'].':'.$pair['material_id'] => $index,
            ]);
        $items = collect($items)
            ->sortBy(static fn (array $item): int => (int) $positionOrder->get(
                $item['warehouse_id'].':'.$item['material_id'],
                PHP_INT_MAX,
            ))
            ->values()
            ->all();

        $reservedByMaterial = DB::query()
            ->fromSub(clone $positionQuery, 'filtered_stock_positions')
            ->selectRaw('material_id, SUM(reserved_quantity) AS reserved_quantity')
            ->groupBy('material_id')
            ->havingRaw('SUM(reserved_quantity) > 0')
            ->get();
        $materials = Material::query()
            ->whereIn('id', $reservedByMaterial->pluck('material_id'))
            ->with('measurementUnit:id,name,short_name')
            ->get()
            ->keyBy('id');
        $reservedQuantities = $reservedByMaterial
            ->groupBy(static function ($position) use ($materials): string {
                $material = $materials->get($position->material_id);

                return $material?->measurementUnit?->short_name
                    ?? $material?->measurementUnit?->name
                    ?? '';
            })
            ->map(static fn (Collection $positions, string $measurementUnit): array => [
                'measurement_unit' => $measurementUnit,
                'quantity' => (float) $positions->sum('reserved_quantity'),
            ])
            ->values()
            ->all();

        $from = $total === 0 || $items === [] ? null : (($page - 1) * $perPage) + 1;

        return [
            'items' => $items,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $from,
                'to' => $from === null ? null : $from + count($items) - 1,
            ],
            'summary' => [
                'total_items' => $total,
                'low_stock_count' => (int) ($summary->low_stock_count ?? 0),
                'total_value' => (float) ($summary->total_value ?? 0),
                'reserved_quantities' => $reservedQuantities,
            ],
        ];
    }

    private function buildStockQuery(int $organizationId, array $filters): Builder
    {
        $query = WarehouseBalance::query()
            ->where('organization_id', $organizationId)
            ->where(function (Builder $query): void {
                $query->where('available_quantity', '>', 0)
                    ->orWhere('reserved_quantity', '>', 0);
            })
            ->with(['material.measurementUnit', 'warehouse.project', 'cell.zone', 'material.photos']);

        if (isset($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }

        if (isset($filters['asset_type'])) {
            $query->whereHas('material', function ($materialQuery) use ($filters): void {
                $driver = $materialQuery->getConnection()->getDriverName();
                if ($driver === 'pgsql') {
                    $materialQuery->whereRaw("additional_properties->>'asset_type' = ?", [$filters['asset_type']]);
                } else {
                    $materialQuery->whereRaw("JSON_EXTRACT(additional_properties, '$.asset_type') = ?", [$filters['asset_type']]);
                }
            });
        }

        if (isset($filters['category'])) {
            $query->whereHas('material', static function (Builder $materialQuery) use ($filters): void {
                $materialQuery->where('category', $filters['category']);
            });
        }

        if (! empty($filters['search'])) {
            $search = mb_strtolower(trim((string) $filters['search']));
            $query->whereHas('material', static function (Builder $materialQuery) use ($search): void {
                $materialQuery->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery->whereRaw('LOWER(name) LIKE ?', ['%'.$search.'%'])
                        ->orWhereRaw('LOWER(code) LIKE ?', ['%'.$search.'%']);
                });
            });
        }

        $this->applyStockLocationFilters($query, $organizationId, $filters);

        if (! empty($filters['missing_location'])) {
            $query->whereExists(static function ($missingLocationQuery) use ($organizationId): void {
                $missingLocationQuery
                    ->selectRaw('1')
                    ->from('warehouse_balances as missing_location_balances')
                    ->whereColumn('missing_location_balances.warehouse_id', 'warehouse_balances.warehouse_id')
                    ->whereColumn('missing_location_balances.material_id', 'warehouse_balances.material_id')
                    ->where('missing_location_balances.organization_id', $organizationId)
                    ->whereNull('missing_location_balances.cell_id')
                    ->where(function ($quantityQuery): void {
                        $quantityQuery->where('missing_location_balances.available_quantity', '>', 0)
                            ->orWhere('missing_location_balances.reserved_quantity', '>', 0);
                    })
                    ->where(function ($locationQuery): void {
                        $locationQuery->whereNull('missing_location_balances.location_code')
                            ->orWhere('missing_location_balances.location_code', '');
                    });
            });
        }

        if (! empty($filters['low_stock'])) {
            $query->lowStock();
        }

        if (isset($filters['project_id'])) {
            $query->whereExists(function ($allocationQuery) use ($filters): void {
                $allocationQuery
                    ->selectRaw('1')
                    ->from('warehouse_project_allocations as project_filter_allocations')
                    ->whereColumn('project_filter_allocations.organization_id', 'warehouse_balances.organization_id')
                    ->whereColumn('project_filter_allocations.warehouse_id', 'warehouse_balances.warehouse_id')
                    ->whereColumn('project_filter_allocations.material_id', 'warehouse_balances.material_id')
                    ->where('project_filter_allocations.project_id', $filters['project_id']);
            });
        }

        if (! empty($filters['position_pairs'])) {
            $query->where(static function (Builder $positionQuery) use ($filters): void {
                foreach ($filters['position_pairs'] as $pair) {
                    $positionQuery->orWhere(static function (Builder $pairQuery) use ($pair): void {
                        $pairQuery->where('warehouse_id', $pair['warehouse_id'])
                            ->where('material_id', $pair['material_id']);
                    });
                }
            });
        }

        return $query;
    }

    private function applyStockLocationFilters(Builder $query, int $organizationId, array $filters): void
    {
        if (! empty($filters['location_code'])) {
            $query->where('location_code', $filters['location_code']);
        }

        if (! empty($filters['cell_id'])) {
            $query->where('cell_id', $filters['cell_id']);
        }

        if (empty($filters['zone_id'])) {
            return;
        }

        $zoneId = (int) $filters['zone_id'];
        $query->where(function (Builder $locationQuery) use ($organizationId, $zoneId): void {
            $locationQuery
                ->whereHas('cell', static function (Builder $cellQuery) use ($organizationId, $zoneId): void {
                    $cellQuery
                        ->where('organization_id', $organizationId)
                        ->where('zone_id', $zoneId);
                })
                ->orWhere(function (Builder $legacyLocationQuery) use ($organizationId, $zoneId): void {
                    $legacyLocationQuery
                        ->whereNull('cell_id')
                        ->whereIn('location_code', DB::table('warehouse_storage_cells')
                            ->select('code')
                            ->where('organization_id', $organizationId)
                            ->whereColumn('warehouse_storage_cells.warehouse_id', 'warehouse_balances.warehouse_id')
                            ->where('zone_id', $zoneId));
                });
        });
    }

    private function purchaseReceiptDocumentMap(int $organizationId, Collection $batches): array
    {
        $warehouseIds = $batches->pluck('warehouse_id')->filter()->unique()->values();
        $materialIds = $batches->pluck('material_id')->filter()->unique()->values();

        if ($warehouseIds->isEmpty() || $materialIds->isEmpty()) {
            return [];
        }

        $receiptLines = PurchaseReceiptLine::query()
            ->with([
                'purchaseReceipt.purchaseOrder.supplier',
                'purchaseReceipt.purchaseOrder.externalSupplierContact',
                'purchaseReceipt.purchaseOrder.supplierParty',
                'purchaseOrderItem',
            ])
            ->whereHas('purchaseReceipt', static function ($query) use ($organizationId, $warehouseIds): void {
                $query->where('organization_id', $organizationId)
                    ->where('status', PurchaseReceiptStatusEnum::POSTED->value)
                    ->whereIn('warehouse_id', $warehouseIds);
            })
            ->whereHas('purchaseOrderItem', static function ($query) use ($materialIds): void {
                $query->whereIn('material_id', $materialIds);
            })
            ->get()
            ->filter(static function (PurchaseReceiptLine $line): bool {
                $receipt = $line->purchaseReceipt;
                $item = $line->purchaseOrderItem;

                return $receipt !== null
                    && $item !== null
                    && $item->material_id !== null
                    && is_array(data_get($receipt->metadata, 'receipt_document'));
            });

        return $receiptLines
            ->groupBy(static function (PurchaseReceiptLine $line): string {
                return $line->purchaseReceipt->warehouse_id.':'.$line->purchaseOrderItem->material_id;
            })
            ->map(fn (Collection $lines): array => $lines
                ->groupBy('purchase_receipt_id')
                ->map(function (Collection $receiptLines): array {
                    /** @var PurchaseReceiptLine $firstLine */
                    $firstLine = $receiptLines->first();
                    $receipt = $firstLine->purchaseReceipt;
                    $order = $receipt?->purchaseOrder;

                    return [
                        'receipt_id' => (int) $receipt->id,
                        'purchase_order_id' => (int) $receipt->purchase_order_id,
                        'warehouse_id' => (int) $receipt->warehouse_id,
                        'receipt_number' => (string) $receipt->receipt_number,
                        'receipt_date' => $receipt->receipt_date?->format('Y-m-d'),
                        'order_number' => $order?->order_number,
                        'supplier_name' => $this->purchaseReceiptSupplierName($order),
                        'quantity' => round((float) $receiptLines->sum('quantity_received'), 3),
                        'total_amount' => round((float) $receiptLines->sum('total_amount'), 2),
                        'has_pdf' => true,
                    ];
                })
                ->sortByDesc(static fn (array $document): string => (string) ($document['receipt_date'] ?? ''))
                ->take(5)
                ->values()
                ->all())
            ->all();
    }

    private function purchaseReceiptSupplierName(?PurchaseOrder $order): ?string
    {
        if (! $order) {
            return null;
        }

        $snapshot = is_array($order->supplier_snapshot) ? $order->supplier_snapshot : [];

        return $order->supplier?->name
            ?? $order->externalSupplierContact?->name
            ?? $order->supplierParty?->display_name
            ?? $snapshot['display_name']
            ?? $snapshot['name']
            ?? null;
    }

    /**
     * Получить данные по движению активов для отчетов
     */
    public function getMovementsData(int $organizationId, array $filters = []): array
    {
        $movements = $this->movementQuery($organizationId, $filters)
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->get();
        $actorNames = $this->movementActorNames($organizationId, $movements);
        $relatedUserIdentities = $this->movementRelatedUserIdentities($organizationId, $movements);

        return $movements
            ->map(fn (WarehouseMovement $movement): array => $this->serializeMovement(
                $movement,
                $actorNames[(int) $movement->id] ?? null,
                $relatedUserIdentities[(int) $movement->id] ?? null,
            ))
            ->all();
    }

    public function paginateMovementsData(
        int $organizationId,
        array $filters = [],
        int $perPage = 20,
        int $page = 1,
    ): LengthAwarePaginator {
        $paginator = $this->movementQuery($organizationId, $filters)
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->paginate(
                perPage: max(1, min($perPage, 100)),
                page: max(1, $page),
            );

        $movements = $paginator->getCollection();
        $actorNames = $this->movementActorNames($organizationId, $movements);
        $relatedUserIdentities = $this->movementRelatedUserIdentities($organizationId, $movements);
        $paginator->setCollection($movements->map(
            fn (WarehouseMovement $movement): array => $this->serializeMovement(
                $movement,
                $actorNames[(int) $movement->id] ?? null,
                $relatedUserIdentities[(int) $movement->id] ?? null,
            ),
        ));
        $paginator->appends(array_filter([
            'material_id' => $filters['material_id'] ?? null,
            'movement_type' => $filters['movement_type'] ?? null,
            'date_from' => $filters['date_from'] ?? null,
            'date_to' => $filters['date_to'] ?? null,
            'search' => $filters['search'] ?? null,
            'per_page' => $paginator->perPage(),
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));

        return $paginator;
    }

    private function movementQuery(int $organizationId, array $filters): Builder
    {
        $query = WarehouseMovement::query()
            ->where('organization_id', $organizationId)
            ->with([
                'material.measurementUnit',
                'warehouse',
                'fromWarehouse',
                'toWarehouse',
                'cell.zone',
                'project',
                'user',
                'relatedUser',
                'photos',
            ]);

        if (isset($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }

        if (isset($filters['material_id'])) {
            $query->where('material_id', $filters['material_id']);
        }

        if (isset($filters['movement_type'])) {
            $query->where('movement_type', $filters['movement_type']);
        }

        if (isset($filters['date_from'])) {
            $query->where('movement_date', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('movement_date', '<=', $filters['date_to']);
        }

        if (isset($filters['asset_type'])) {
            $query->whereHas('material', function (Builder $materialQuery) use ($filters): void {
                $driver = $materialQuery->getConnection()->getDriverName();
                if ($driver === 'pgsql') {
                    $materialQuery->whereRaw("additional_properties->>'asset_type' = ?", [$filters['asset_type']]);
                } else {
                    $materialQuery->whereRaw("JSON_EXTRACT(additional_properties, '$.asset_type') = ?", [$filters['asset_type']]);
                }
            });
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $pattern = '%'.$search.'%';
            $query->where(function (Builder $searchQuery) use ($organizationId, $pattern, $search): void {
                $searchQuery
                    ->whereLike('document_number', $pattern)
                    ->orWhereLike('reason', $pattern)
                    ->orWhereHas('material', static function (Builder $materialQuery) use ($pattern): void {
                        $materialQuery
                            ->whereLike('name', $pattern)
                            ->orWhereLike('code', $pattern);
                    })
                    ->orWhereHas('project', static function (Builder $projectQuery) use ($pattern): void {
                        $projectQuery->whereLike('name', $pattern);
                    });

                $this->personNameProvider->orWhereEmployeeNameMatches(
                    $searchQuery,
                    $organizationId,
                    $search,
                    'warehouse_movements.user_id',
                    'warehouse_movements.movement_date',
                );
            });
        }

        return $query;
    }

    private function movementActorNames(int $organizationId, Collection $movements): array
    {
        $references = $movements
            ->filter(static fn (WarehouseMovement $movement): bool => $movement->user_id !== null)
            ->mapWithKeys(static fn (WarehouseMovement $movement): array => [
                (int) $movement->id => [
                    'user_id' => (int) $movement->user_id,
                    'date' => $movement->movement_date,
                ],
            ])
            ->all();
        $identities = $this->personIdentityResolver->resolveMany($organizationId, $references);

        return $movements
            ->mapWithKeys(static function (WarehouseMovement $movement) use ($identities): array {
                return [
                    (int) $movement->id => ($identities[(int) $movement->id]['name'] ?? null)
                        ?? trans_message('warehouse_basic.document_person_not_specified'),
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array{name: string, email: ?string}>
     */
    private function movementRelatedUserIdentities(int $organizationId, Collection $movements): array
    {
        $references = $movements
            ->filter(static fn (WarehouseMovement $movement): bool => $movement->related_user_id !== null)
            ->mapWithKeys(static fn (WarehouseMovement $movement): array => [
                (int) $movement->id => [
                    'user_id' => (int) $movement->related_user_id,
                    'date' => $movement->movement_date,
                ],
            ])
            ->all();

        return $this->personIdentityResolver->resolveMany($organizationId, $references);
    }

    /**
     * @param  array{name: string, email: ?string}|null  $relatedUserIdentity
     */
    private function serializeMovement(
        WarehouseMovement $movement,
        ?string $actorName,
        ?array $relatedUserIdentity,
    ): array
    {
        return [
            'movement_id' => $movement->id,
            'movement_type' => $movement->movement_type,
            'transfer_pair_key' => $movement->metadata['transfer_pair_key'] ?? null,
            'operation_category' => $movement->operation_category,
            'operation_category_label' => $movement->operationCategoryLabel(),
            'warehouse_id' => $movement->warehouse_id,
            'warehouse_name' => $movement->warehouse->name,
            'from_warehouse_id' => $movement->from_warehouse_id,
            'from_warehouse_name' => $movement->fromWarehouse?->name,
            'to_warehouse_id' => $movement->to_warehouse_id,
            'to_warehouse_name' => $movement->toWarehouse?->name,
            'cell_id' => $movement->cell_id,
            'cell' => $movement->cell ? [
                'id' => $movement->cell->id,
                'code' => $movement->cell->code,
                'name' => $movement->cell->name,
                'full_address' => $movement->cell->full_address,
                'zone' => $movement->cell->zone ? [
                    'id' => $movement->cell->zone->id,
                    'code' => $movement->cell->zone->code,
                    'name' => $movement->cell->zone->name,
                ] : null,
            ] : null,
            'storage_address' => $movement->cell?->full_address ?? $movement->metadata['storage_address'] ?? null,
            'material_id' => $movement->material_id,
            'material_name' => $movement->material->name,
            'material_code' => $movement->material->code,
            'quantity' => (float) $movement->quantity,
            'price' => (float) $movement->price,
            'total_value' => (float) $movement->quantity * (float) $movement->price,
            'measurement_unit' => $movement->material->measurementUnit->short_name
                ?? $movement->material->measurementUnit->name
                ?? null,
            'project_id' => $movement->project_id,
            'project_name' => $movement->project->name ?? null,
            'user_name' => $actorName,
            'related_user_id' => $movement->related_user_id,
            'related_user_name' => $relatedUserIdentity['name'] ?? $movement->relatedUser->name ?? null,
            'related_user' => $movement->relatedUser ? [
                'id' => $movement->relatedUser->id,
                'name' => $relatedUserIdentity['name'] ?? $movement->relatedUser->name,
                'email' => $relatedUserIdentity !== null
                    ? $relatedUserIdentity['email']
                    : $movement->relatedUser->email,
            ] : null,
            'project_material_delivery_id' => $movement->project_material_delivery_id,
            'document_number' => $movement->document_number,
            'reason' => $movement->reason,
            'movement_date' => $movement->movement_date->toDateTimeString(),
            'photo_gallery' => $movement->photo_gallery,
        ];
    }

    private function makeQrDataUri(string $payload): string
    {
        $options = new QROptions([
            'outputType' => QRCode::OUTPUT_MARKUP_SVG,
            'eccLevel' => QRCode::ECC_M,
            'scale' => 6,
            'imageBase64' => false,
            'quietzoneSize' => 2,
        ]);

        $svg = (new QRCode($options))->render($payload);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Получить данные инвентаризации для отчетов
     */
    public function getInventoryData(int $organizationId, array $filters = []): array
    {
        $query = \App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct::where('organization_id', $organizationId)
            ->with(['warehouse', 'creator', 'items.material']);

        // Применяем фильтры
        if (isset($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['date_from'])) {
            $query->where('inventory_date', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('inventory_date', '<=', $filters['date_to']);
        }

        $acts = $query->orderBy('inventory_date', 'desc')->get();

        return $acts->map(function ($act) {
            return [
                'act_id' => $act->id,
                'act_number' => $act->act_number,
                'warehouse_id' => $act->warehouse_id,
                'warehouse_name' => $act->warehouse->name,
                'status' => $act->status,
                'inventory_date' => $act->inventory_date->toDateString(),
                'created_by' => $act->creator->name,
                'items_count' => $act->items->count(),
                'discrepancies_count' => $act->items->filter(fn ($item) => $item->hasDiscrepancy())->count(),
                'total_difference_value' => $act->items->sum('total_value'),
                'started_at' => $act->started_at?->toDateTimeString(),
                'completed_at' => $act->completed_at?->toDateTimeString(),
                'approved_at' => $act->approved_at?->toDateTimeString(),
                'items' => $act->items->map(function ($item) {
                    return [
                        'material_id' => $item->material_id,
                        'material_name' => $item->material->name,
                        'expected_quantity' => (float) $item->expected_quantity,
                        'actual_quantity' => (float) $item->actual_quantity,
                        'difference' => (float) $item->difference,
                        'unit_price' => (float) $item->unit_price,
                        'total_value' => (float) $item->total_value,
                        'has_discrepancy' => $item->hasDiscrepancy(),
                    ];
                })->toArray(),
            ];
        })->toArray();
    }

    /**
     * Получить данные аналитики оборачиваемости
     */
    public function getTurnoverAnalytics(int $organizationId, array $filters = []): array
    {
        $dateFrom = $filters['date_from'] ?? now()->subMonth();
        $dateTo = $filters['date_to'] ?? now();

        // Получаем движения за период
        $movements = WarehouseMovement::where('organization_id', $organizationId)
            ->whereBetween('movement_date', [$dateFrom, $dateTo])
            ->with(['material'])
            ->get();

        // Группируем по материалам
        $assetAnalytics = [];
        $materialIds = $movements->pluck('material_id')->unique();

        foreach ($materialIds as $materialId) {
            $materialMovements = $movements->where('material_id', $materialId);
            $material = $materialMovements->first()->material;

            // Расход за период (write_off)
            $consumption = $materialMovements
                ->where('movement_type', 'write_off')
                ->sum('quantity');

            // Средний остаток (упрощенно - текущий остаток)
            $balance = WarehouseBalance::where('organization_id', $organizationId)
                ->where('material_id', $materialId)
                ->first();

            $averageStock = $balance ? (float) $balance->available_quantity : 0;

            // Коэффициент оборачиваемости
            $turnoverRate = $averageStock > 0 ? $consumption / $averageStock : 0;

            // Период оборачиваемости в днях
            $days = $dateFrom->diffInDays($dateTo);
            $turnoverDays = $turnoverRate > 0 ? $days / $turnoverRate : 0;

            // ABC категория (упрощенно - по потреблению)
            $category = $turnoverRate > 2 ? 'A' : ($turnoverRate > 0.5 ? 'B' : 'C');

            $assetAnalytics[] = [
                'asset_id' => $materialId,
                'asset_name' => $material->name,
                'asset_code' => $material->code,
                'average_stock' => $averageStock,
                'consumption' => (float) $consumption,
                'turnover_rate' => round($turnoverRate, 2),
                'turnover_days' => round($turnoverDays, 0),
                'category' => $category,
            ];
        }

        // Сортируем по оборачиваемости
        usort($assetAnalytics, fn ($a, $b) => $b['turnover_rate'] <=> $a['turnover_rate']);

        return [
            'period' => [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'days' => $dateFrom->diffInDays($dateTo),
            ],
            'assets' => $assetAnalytics,
            'summary' => [
                'total_assets_analyzed' => count($assetAnalytics),
                'average_turnover_rate' => count($assetAnalytics) > 0
                    ? round(collect($assetAnalytics)->avg('turnover_rate'), 2)
                    : 0,
                'slow_moving_count' => collect($assetAnalytics)->where('category', 'C')->count(),
                'fast_moving_count' => collect($assetAnalytics)->where('category', 'A')->count(),
            ],
        ];
    }

    public function getTurnoverAnalyticsReport(int $organizationId, array $filters = []): array
    {
        $dateFrom = isset($filters['date_from'])
            ? Carbon::parse((string) $filters['date_from'])->startOfDay()
            : now()->subMonth()->startOfDay();
        $dateTo = isset($filters['date_to'])
            ? Carbon::parse((string) $filters['date_to'])->endOfDay()
            : now()->endOfDay();
        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;

        $movements = WarehouseMovement::query()
            ->where('organization_id', $organizationId)
            ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->whereBetween('movement_date', [$dateFrom, $dateTo])
            ->with(['material.measurementUnit:id,short_name'])
            ->get();

        $days = max(1, $dateFrom->diffInDays($dateTo) + 1);
        $materials = [];
        $legacyAssets = [];
        $materialIds = $movements->pluck('material_id')->filter()->unique()->values();

        foreach ($materialIds as $materialId) {
            $materialMovements = $movements->where('material_id', $materialId);
            $material = $materialMovements->first()?->material;

            if (! $material) {
                continue;
            }

            $consumption = (float) $materialMovements
                ->where('movement_type', 'write_off')
                ->sum('quantity');

            $averageStock = (float) WarehouseBalance::query()
                ->where('organization_id', $organizationId)
                ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))
                ->where('material_id', (int) $materialId)
                ->sum('available_quantity');

            $turnoverRatio = $averageStock > 0 ? $consumption / $averageStock : 0.0;
            $daysSupply = $turnoverRatio > 0 ? $days / $turnoverRatio : 0.0;
            $category = $turnoverRatio > 2
                ? 'fast_moving'
                : ($turnoverRatio > 0.5 ? 'medium_moving' : 'slow_moving');

            $materialPayload = [
                'material_id' => (int) $materialId,
                'material_name' => (string) $material->name,
                'material_code' => (string) ($material->code ?? ''),
                'measurement_unit' => $material->measurementUnit?->short_name,
                'average_stock' => round($averageStock, 2),
                'total_consumption' => round($consumption, 2),
                'turnover_ratio' => round($turnoverRatio, 2),
                'days_supply' => round($daysSupply, 1),
                'category' => $category,
            ];

            $materials[] = $materialPayload;
            $legacyAssets[] = [
                'asset_id' => $materialPayload['material_id'],
                'asset_name' => $materialPayload['material_name'],
                'asset_code' => $materialPayload['material_code'],
                'average_stock' => $materialPayload['average_stock'],
                'consumption' => $materialPayload['total_consumption'],
                'turnover_rate' => $materialPayload['turnover_ratio'],
                'turnover_days' => $materialPayload['days_supply'],
                'category' => match ($category) {
                    'fast_moving' => 'A',
                    'medium_moving' => 'B',
                    default => 'C',
                },
            ];
        }

        usort($materials, fn (array $left, array $right) => $right['turnover_ratio'] <=> $left['turnover_ratio']);
        usort($legacyAssets, fn (array $left, array $right) => $right['turnover_rate'] <=> $left['turnover_rate']);

        return [
            'period' => [
                'from' => $dateFrom->toDateString(),
                'to' => $dateTo->toDateString(),
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'days' => $days,
            ],
            'materials' => $materials,
            'assets' => $legacyAssets,
            'summary' => [
                'total_materials' => count($materials),
                'total_assets_analyzed' => count($legacyAssets),
                'average_turnover_ratio' => count($materials) > 0
                    ? round((float) collect($materials)->avg('turnover_ratio'), 2)
                    : 0.0,
                'average_turnover_rate' => count($legacyAssets) > 0
                    ? round((float) collect($legacyAssets)->avg('turnover_rate'), 2)
                    : 0.0,
                'fast_moving' => collect($materials)->where('category', 'fast_moving')->count(),
                'medium_moving' => collect($materials)->where('category', 'medium_moving')->count(),
                'slow_moving' => collect($materials)->where('category', 'slow_moving')->count(),
                'fast_moving_count' => collect($legacyAssets)->where('category', 'A')->count(),
                'slow_moving_count' => collect($legacyAssets)->where('category', 'C')->count(),
            ],
        ];
    }

    /**
     * Получить прогноз потребности в материалах
     */
    public function getForecastData(int $organizationId, array $filters = []): array
    {
        $horizonDays = (int) ($filters['horizon_days'] ?? 90);
        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        $assetIds = collect($filters['asset_ids'] ?? [])
            ->map(static fn ($assetId) => (int) $assetId)
            ->filter()
            ->values()
            ->all();
        $historicalDays = 90; // Анализируем последние 90 дней

        $dateFrom = now()->subDays($historicalDays);
        $dateTo = now();

        // Получаем движения за исторический период
        $movements = WarehouseMovement::where('organization_id', $organizationId)
            ->whereBetween('movement_date', [$dateFrom, $dateTo])
            ->where('movement_type', 'write_off')
            ->when($warehouseId !== null, static fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->when($assetIds !== [], static fn ($query) => $query->whereIn('material_id', $assetIds))
            ->with(['material.measurementUnit:id,short_name'])
            ->get();

        $forecasts = [];
        $materialIds = $movements->pluck('material_id')->unique();

        foreach ($materialIds as $materialId) {
            $materialMovements = $movements->where('material_id', $materialId);
            $material = $materialMovements->first()->material;

            // Простой линейный прогноз: средний расход в день * горизонт
            $totalConsumption = $materialMovements->sum('quantity');
            $averageDailyConsumption = $totalConsumption / $historicalDays;
            $predictedConsumption = $averageDailyConsumption * $horizonDays;

            // Текущий остаток (сумма по всем партиям)
            $currentStock = WarehouseBalance::where('organization_id', $organizationId)
                ->where('material_id', $materialId)
                ->when($warehouseId !== null, static fn ($query) => $query->where('warehouse_id', $warehouseId))
                ->sum('available_quantity');

            // Дата исчерпания запасов
            $daysUntilStockOut = $averageDailyConsumption > 0
                ? $currentStock / $averageDailyConsumption
                : 999999;

            // Рекомендуемое количество заказа (покрытие на 30 дней)
            $recommendedOrderQuantity = max(0, $averageDailyConsumption * 30 - $currentStock);

            // Уровень уверенности (упрощенно - на основе стабильности потребления)
            $consumptionVariance = $this->calculateVariance(
                $materialMovements->pluck('quantity')->toArray()
            );
            $confidence = max(50, min(95, 100 - ($consumptionVariance * 10)));

            $forecasts[] = [
                'asset_id' => $materialId,
                'asset_name' => $material->name,
                'asset_code' => $material->code,
                'measurement_unit' => $material->measurementUnit?->short_name,
                'current_stock' => $currentStock,
                'average_daily_consumption' => round($averageDailyConsumption, 2),
                'predicted_consumption' => round($predictedConsumption, 2),
                'recommended_order_quantity' => round($recommendedOrderQuantity, 2),
                'estimated_stock_out_date' => $daysUntilStockOut < $horizonDays
                    ? now()->addDays((int) $daysUntilStockOut)->toDateString()
                    : null,
                'days_until_stock_out' => min((int) $daysUntilStockOut, $horizonDays),
                'confidence' => (int) $confidence,
                'forecast_method' => 'linear_average',
            ];
        }

        // Сортируем по срочности
        usort($forecasts, fn ($a, $b) => $a['days_until_stock_out'] <=> $b['days_until_stock_out']);

        // Разделяем по приоритетам
        $immediateOrders = collect($forecasts)->filter(fn ($f) => $f['days_until_stock_out'] < 7)->values()->toArray();
        $plannedOrders = collect($forecasts)->filter(fn ($f) => $f['days_until_stock_out'] >= 7 && $f['days_until_stock_out'] < 30)->values()->toArray();
        $excessiveStock = collect($forecasts)->filter(fn ($f) => $f['days_until_stock_out'] > 180)->values()->toArray();

        return [
            'forecast_period' => [
                'start_date' => now()->toDateString(),
                'end_date' => now()->addDays($horizonDays)->toDateString(),
                'horizon_days' => $horizonDays,
                'historical_days' => $historicalDays,
            ],
            'forecasts' => $forecasts,
            'recommendations' => [
                'immediate_orders' => $immediateOrders,
                'planned_orders' => $plannedOrders,
                'excessive_stock' => $excessiveStock,
            ],
            'summary' => [
                'total_assets_forecasted' => count($forecasts),
                'immediate_attention_required' => count($immediateOrders),
                'planned_orders_required' => count($plannedOrders),
                'excessive_stock_count' => count($excessiveStock),
            ],
        ];
    }

    /**
     * Получить ABC/XYZ анализ запасов
     */
    public function getAbcXyzAnalysis(int $organizationId, array $filters = []): array
    {
        $dateFrom = isset($filters['date_from'])
            ? Carbon::parse((string) $filters['date_from'])->startOfDay()
            : now()->subYear()->startOfDay();
        $dateTo = isset($filters['date_to'])
            ? Carbon::parse((string) $filters['date_to'])->endOfDay()
            : now()->endOfDay();
        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;

        // Получаем движения за период
        $movements = WarehouseMovement::where('organization_id', $organizationId)
            ->whereBetween('movement_date', [$dateFrom, $dateTo])
            ->where('movement_type', 'write_off')
            ->when($warehouseId !== null, static fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->with(['material'])
            ->get();

        $assetAnalysis = [];
        $materialIds = $movements->pluck('material_id')->unique();
        $totalValue = 0;

        // Первый проход: рассчитываем стоимость потребления для каждого актива
        foreach ($materialIds as $materialId) {
            $materialMovements = $movements->where('material_id', $materialId);
            $material = $materialMovements->first()->material;

            // Стоимость потребления за период
            $consumptionValue = $materialMovements->sum(function ($m) {
                return (float) $m->quantity * (float) $m->price;
            });

            // Коэффициент вариации для XYZ
            $quantities = $materialMovements->pluck('quantity')->toArray();
            $variance = $this->calculateVariance($quantities);

            $assetAnalysis[] = [
                'asset_id' => $materialId,
                'asset_name' => $material->name,
                'asset_code' => $material->code,
                'total_value' => $consumptionValue,
                'consumption_variance' => $variance,
            ];

            $totalValue += $consumptionValue;
        }

        // Сортируем по стоимости для ABC анализа
        usort($assetAnalysis, fn ($a, $b) => $b['total_value'] <=> $a['total_value']);

        // Второй проход: присваиваем ABC категории (правило Парето)
        $cumulativePercent = 0;
        foreach ($assetAnalysis as &$asset) {
            $asset['value_percent'] = $totalValue > 0 ? ($asset['total_value'] / $totalValue) * 100 : 0;
            $cumulativePercent += $asset['value_percent'];

            // ABC категории: A=80%, B=15%, C=5%
            if ($cumulativePercent <= 80) {
                $asset['abc_category'] = 'A';
            } elseif ($cumulativePercent <= 95) {
                $asset['abc_category'] = 'B';
            } else {
                $asset['abc_category'] = 'C';
            }

            // XYZ категории по коэффициенту вариации
            if ($asset['consumption_variance'] < 0.1) {
                $asset['xyz_category'] = 'X';
            } elseif ($asset['consumption_variance'] < 0.25) {
                $asset['xyz_category'] = 'Y';
            } else {
                $asset['xyz_category'] = 'Z';
            }

            $asset['combined_category'] = $asset['abc_category'].$asset['xyz_category'];

            // Рекомендации по категориям
            $asset['recommendation'] = $this->getAbcXyzRecommendation($asset['combined_category']);
        }

        // Подсчет распределения
        $abcDistribution = [
            'A' => ['count' => collect($assetAnalysis)->where('abc_category', 'A')->count(), 'value_percent' => 80],
            'B' => ['count' => collect($assetAnalysis)->where('abc_category', 'B')->count(), 'value_percent' => 15],
            'C' => ['count' => collect($assetAnalysis)->where('abc_category', 'C')->count(), 'value_percent' => 5],
        ];

        $xyzDistribution = [
            'X' => ['count' => collect($assetAnalysis)->where('xyz_category', 'X')->count(), 'stability' => 'high'],
            'Y' => ['count' => collect($assetAnalysis)->where('xyz_category', 'Y')->count(), 'stability' => 'medium'],
            'Z' => ['count' => collect($assetAnalysis)->where('xyz_category', 'Z')->count(), 'stability' => 'low'],
        ];

        return [
            'analysis_period' => [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
            ],
            'abc_distribution' => $abcDistribution,
            'xyz_distribution' => $xyzDistribution,
            'assets' => $assetAnalysis,
            'recommendations' => [
                'AX' => 'Критические товары со стабильным спросом - строгий контроль, минимальные запасы, частые поставки',
                'AY' => 'Критические товары со средней стабильностью - повышенные страховые запасы',
                'AZ' => 'Критические товары с нестабильным спросом - максимальные страховые запасы, анализ причин',
                'BX' => 'Важные товары со стабильным спросом - стандартный контроль, средние запасы',
                'BY' => 'Важные товары со средней стабильностью - средние страховые запасы',
                'BZ' => 'Важные товары с нестабильным спросом - повышенные страховые запасы',
                'CX' => 'Малоценные товары со стабильным спросом - упрощенный контроль, закупка большими партиями',
                'CY' => 'Малоценные товары со средней стабильностью - стандартные запасы',
                'CZ' => 'Малоценные товары с нестабильным спросом - минимальный контроль, закупка по мере необходимости',
            ],
            'summary' => [
                'total_assets_analyzed' => count($assetAnalysis),
                'total_consumption_value' => round($totalValue, 2),
                'critical_assets_count' => $abcDistribution['A']['count'],
                'stable_assets_count' => $xyzDistribution['X']['count'],
            ],
        ];
    }

    /**
     * Зарезервировать активы для проекта
     */
    /**
     * Зарезервировать количество актива на складе (FIFO)
     * Обновляет балансы партий, перенося из available в reserved
     */
    public function reserveQuantity(
        int $organizationId,
        int $warehouseId,
        int $materialId,
        float $quantity,
        array $metadata = [],
    ): WarehouseMovement {
        $batches = WarehouseBalance::where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->where('material_id', $materialId)
            ->where('available_quantity', '>', 0)
            ->orderByRaw('CASE WHEN expiry_date IS NOT NULL THEN expiry_date ELSE created_at END ASC')
            ->lockForUpdate()
            ->get();

        $totalAvailable = $batches->sum('available_quantity');

        if ($totalAvailable < $quantity) {
            throw new \InvalidArgumentException(
                trans_message('warehouse_basic.validation.insufficient_reserve_stock', [
                    'available' => (float) $totalAvailable,
                    'requested' => (float) $quantity,
                ])
            );
        }

        $remainingToReserve = $quantity;

        foreach ($batches as $batch) {
            if ($remainingToReserve <= 0) {
                break;
            }

            $takeFromBatch = min($batch->available_quantity, $remainingToReserve);

            $batch->reserve($takeFromBatch);

            $remainingToReserve -= $takeFromBatch;
        }
        $metadata = $this->reportingMetadata($organizationId, $warehouseId, $materialId, $metadata);
        $movement = WarehouseMovement::create([
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'movement_type' => 'reservation',
            'quantity' => $quantity,
            'project_id' => $metadata['project_id'] ?? null,
            'user_id' => $metadata['user_id'] ?? null,
            'reason' => $metadata['reason'] ?? null,
            'metadata' => $metadata,
            'movement_date' => now(),
        ]);
        $this->inventoryEventRecorder->record($movement, 'reservation', null);

        return $movement;
    }

    /**
     * Снять резервирование количества (освободить FIFO/LIFO или просто наличие)
     */
    public function unreserveQuantity(
        int $organizationId,
        int $warehouseId,
        int $materialId,
        float $quantity,
        array $metadata = [],
    ): WarehouseMovement {
        // Ищем партии где есть резерв
        // Снимаем резерв с тех партий, где он есть. Порядок не так важен, но логично снимать с тех,
        // которые скорее всего были зарезервированы последними (LIFO) или первыми (FIFO).
        // Давайте снимать с тех, у кого expiry_date раньше (чтобы освободить "скоропортящиеся" для продажи) => FIFO

        $batches = WarehouseBalance::where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->where('material_id', $materialId)
            ->where('reserved_quantity', '>', 0)
            ->orderByRaw('CASE WHEN expiry_date IS NOT NULL THEN expiry_date ELSE created_at END ASC')
            ->lockForUpdate()
            ->get();

        $totalReserved = $batches->sum('reserved_quantity');

        if ($totalReserved < $quantity) {
            throw new \InvalidArgumentException(
                trans_message('warehouse_basic.validation.insufficient_reserved_stock', [
                    'reserved' => (float) $totalReserved,
                    'requested' => (float) $quantity,
                ])
            );
        }

        $remainingToUnreserve = $quantity;

        foreach ($batches as $batch) {
            if ($remainingToUnreserve <= 0) {
                break;
            }

            $takeFromBatch = min($batch->reserved_quantity, $remainingToUnreserve);

            $batch->unreserve($takeFromBatch);

            $remainingToUnreserve -= $takeFromBatch;
        }
        $metadata = $this->reportingMetadata($organizationId, $warehouseId, $materialId, $metadata);
        $movement = WarehouseMovement::create([
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'movement_type' => 'unreservation',
            'quantity' => $quantity,
            'project_id' => $metadata['project_id'] ?? null,
            'user_id' => $metadata['user_id'] ?? null,
            'reason' => $metadata['reason'] ?? null,
            'metadata' => $metadata,
            'movement_date' => now(),
        ]);
        $this->inventoryEventRecorder->record($movement, 'unreservation', null);

        return $movement;
    }

    public function writeOffReservedAsset(
        int $organizationId,
        int $warehouseId,
        int $materialId,
        float $quantity,
        array $metadata = [],
    ): WarehouseMovement {
        return DB::transaction(function () use (
            $organizationId,
            $warehouseId,
            $materialId,
            $quantity,
            $metadata,
        ): WarehouseMovement {
            $reportingMetadata = $this->reportingMetadata(
                $organizationId,
                $warehouseId,
                $materialId,
                $metadata,
            );
            $batches = WarehouseBalance::query()
                ->where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouseId)
                ->where('material_id', $materialId)
                ->where('reserved_quantity', '>', 0)
                ->orderByRaw('CASE WHEN expiry_date IS NOT NULL THEN expiry_date ELSE created_at END ASC')
                ->lockForUpdate()
                ->get();
            $reserved = (float) $batches->sum('reserved_quantity');
            if ($reserved < $quantity) {
                throw new \InvalidArgumentException(
                    trans_message('warehouse_basic.validation.insufficient_reserved_stock', [
                        'reserved' => $reserved,
                        'requested' => $quantity,
                    ])
                );
            }
            $remaining = $quantity;
            $cost = 0.0;
            foreach ($batches as $batch) {
                if ($remaining <= 0) {
                    break;
                }
                $taken = min((float) $batch->reserved_quantity, $remaining);
                $batch->writeOffReserved($taken);
                $cost += $taken * (float) $batch->unit_price;
                $remaining -= $taken;
            }
            $movement = WarehouseMovement::query()->create([
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
                'movement_type' => WarehouseMovement::TYPE_RESERVED_ISSUE,
                'quantity' => $quantity,
                'price' => $quantity > 0 ? $cost / $quantity : 0,
                'project_id' => $metadata['project_id'] ?? null,
                'user_id' => $metadata['user_id'] ?? null,
                'document_number' => $reportingMetadata['document_number'] ?? null,
                'reason' => $metadata['reason'] ?? null,
                'operation_category' => WarehouseMovement::CATEGORY_PRODUCTION_USAGE,
                'metadata' => $reportingMetadata,
                'movement_date' => now(),
            ]);
            $this->inventoryEventRecorder->record($movement, 'reserved_issue', null);
            $this->clearWarehouseCache($organizationId);

            return $movement;
        }, 3);
    }

    /**
     * Зарезервировать активы для проекта (создает запись AssetReservation)
     */
    public function reserveAssets(
        int $organizationId,
        int $warehouseId,
        int $materialId,
        float $quantity,
        array $metadata = []
    ): array {
        DB::beginTransaction();

        try {
            $this->lockWarehouses($organizationId, [$warehouseId]);
            $metadata = $this->prepareIdempotencyMetadata('reservation', [
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
                'quantity' => $quantity,
                'metadata' => $metadata,
            ], $metadata);
            $key = (string) ($metadata['idempotency_key'] ?? '');
            if ($key !== '') {
                $existingReservation = AssetReservation::query()
                    ->where('organization_id', $organizationId)
                    ->where('metadata->idempotency_key', $key)
                    ->first();
                if ($existingReservation !== null) {
                    if (($existingReservation->metadata['idempotency_fingerprint'] ?? null)
                        !== $metadata['idempotency_fingerprint']) {
                        throw new WarehouseOperationIdempotencyConflictException(
                            trans_message('warehouse_basic.idempotency_conflict')
                        );
                    }

                    $balance = $this->getAssetBalance($organizationId, $warehouseId, $materialId);
                    DB::commit();

                    return [
                        'reserved' => true,
                        'reservation_id' => $existingReservation->id,
                        'quantity' => (float) $existingReservation->quantity,
                        'expires_at' => $existingReservation->expires_at->toIso8601String(),
                        'remaining_available' => $balance ? (float) $balance->availableQuantity : 0,
                    ];
                }
            }

            // Создаем резервацию
            $expiresAt = isset($metadata['expires_hours'])
                ? now()->addHours($metadata['expires_hours'])
                : now()->addHours(24);

            $reservation = AssetReservation::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
                'quantity' => $quantity,
                'project_id' => $metadata['project_id'] ?? null,
                'reserved_by' => $metadata['user_id'] ?? 1,
                'status' => 'active',
                'expires_at' => $expiresAt,
                'reason' => $metadata['reason'] ?? null,
                'metadata' => $metadata,
            ]);

            // Резервируем в балансах (партии)
            $this->reserveQuantity($organizationId, $warehouseId, $materialId, $quantity, $metadata);

            $this->logging->business('warehouse.asset.reserved', [
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
                'quantity' => $quantity,
                'reservation_id' => $reservation->id,
            ]);

            DB::commit();

            // Получаем остаток для возврата (агрегированный)
            $balance = $this->getAssetBalance($organizationId, $warehouseId, $materialId);

            return [
                'reserved' => true,
                'reservation_id' => $reservation->id,
                'quantity' => (float) $quantity,
                'expires_at' => $expiresAt->toIso8601String(),
                'remaining_available' => $balance ? (float) $balance->availableQuantity : 0,
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Снять резервирование
     */
    public function unreserveAssets(int $reservationId): bool
    {
        return $this->transitionReservation($reservationId, AssetReservation::STATUS_CANCELLED);
    }

    public function expireReservation(int $reservationId): bool
    {
        return $this->transitionReservation($reservationId, AssetReservation::STATUS_EXPIRED);
    }

    private function transitionReservation(int $reservationId, string $terminalStatus): bool
    {
        if (! in_array($terminalStatus, [AssetReservation::STATUS_CANCELLED, AssetReservation::STATUS_EXPIRED], true)) {
            throw new \InvalidArgumentException('Unsupported reservation terminal status.');
        }

        DB::beginTransaction();

        try {
            $candidate = AssetReservation::query()->findOrFail($reservationId);
            $activeReservations = AssetReservation::query()
                ->where('organization_id', $candidate->organization_id)
                ->where('warehouse_id', $candidate->warehouse_id)
                ->where('material_id', $candidate->material_id)
                ->where('status', AssetReservation::STATUS_ACTIVE)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $reservation = $activeReservations->firstWhere('id', $reservationId);

            if ($reservation === null
                || ($terminalStatus === AssetReservation::STATUS_EXPIRED && ! $reservation->isExpired())) {
                $reservation = AssetReservation::query()
                    ->where('id', $reservationId)
                    ->where('status', AssetReservation::STATUS_ACTIVE)
                    ->when(
                        $terminalStatus === AssetReservation::STATUS_EXPIRED,
                        static fn ($query) => $query->where('expires_at', '<=', now())
                    )
                    ->firstOrFail();
            }

            $quantitiesByReservation = $this->reservationQuantityService
                ->quantitiesForReservations($activeReservations);
            $remainingQuantity = $quantitiesByReservation[$reservation->id]['remaining_quantity'] ?? 0.0;
            $otherReservationsQuantity = $activeReservations
                ->where('id', '!=', $reservation->id)
                ->sum(
                    static fn (AssetReservation $item): float => $quantitiesByReservation[$item->id]['remaining_quantity'] ?? 0.0
                );
            $reservedQuantity = (float) WarehouseBalance::query()
                ->where('organization_id', $reservation->organization_id)
                ->where('warehouse_id', $reservation->warehouse_id)
                ->where('material_id', $reservation->material_id)
                ->where('reserved_quantity', '>', 0)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->reduce(
                    static fn (float $total, WarehouseBalance $balance): float => $total + (float) $balance->reserved_quantity,
                    0.0,
                );
            $releasedQuantity = min(
                $remainingQuantity,
                max($reservedQuantity - $otherReservationsQuantity, 0.0),
            );
            $shortfallQuantity = max($remainingQuantity - $releasedQuantity, 0.0);

            if ($releasedQuantity > 0) {
                $this->unreserveQuantity(
                    $reservation->organization_id,
                    $reservation->warehouse_id,
                    $reservation->material_id,
                    $releasedQuantity,
                    [
                        'asset_reservation_id' => $reservation->id,
                        'project_id' => $reservation->project_id,
                        'reason' => $reservation->reason,
                    ],
                );
            }

            $metadata = $reservation->metadata ?? [];
            if ($shortfallQuantity > 0.000001) {
                $metadata['release_reconciliation'] = [
                    'expected_quantity' => round($remainingQuantity, 3),
                    'released_quantity' => round($releasedQuantity, 3),
                    'shortfall_quantity' => round($shortfallQuantity, 3),
                    'recorded_at' => now()->toIso8601String(),
                ];
            }

            $reservation->update(array_filter([
                'status' => $terminalStatus,
                'cancelled_at' => $terminalStatus === AssetReservation::STATUS_CANCELLED ? now() : null,
                'metadata' => $metadata,
            ], static fn (mixed $value): bool => $value !== null));

            $this->logging->business(
                $terminalStatus === AssetReservation::STATUS_EXPIRED
                    ? 'warehouse.asset.reservation_expired'
                    : 'warehouse.asset.unreserved',
                [
                    'reservation_id' => $reservationId,
                    'organization_id' => $reservation->organization_id,
                    'quantity' => $reservation->quantity,
                    'released_quantity' => $releasedQuantity,
                    'release_shortfall_quantity' => $shortfallQuantity,
                ]
            );

            DB::commit();

            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function releaseReservedAssets(
        int $organizationId,
        int $warehouseId,
        int $materialId,
        float $quantity,
        array $metadata = []
    ): array {
        DB::beginTransaction();

        try {
            $this->lockWarehouses($organizationId, [$warehouseId]);
            $metadata = $this->prepareIdempotencyMetadata('unreservation', [
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
                'quantity' => $quantity,
                'metadata' => $metadata,
            ], $metadata);
            $existingMovement = $this->findIdempotentMovement(
                $organizationId,
                'unreservation',
                (string) ($metadata['idempotency_key'] ?? ''),
                (string) ($metadata['idempotency_fingerprint'] ?? ''),
            );
            if ($existingMovement !== null) {
                $balance = $this->getAssetBalance($organizationId, $warehouseId, $materialId);
                DB::commit();

                return [
                    'released' => true,
                    'quantity' => (float) $existingMovement->quantity,
                    'released_reservation_ids' => $existingMovement->metadata['released_reservation_ids'] ?? [],
                    'remaining_reserved' => $balance ? (float) $balance->reservedQuantity : 0,
                    'remaining_available' => $balance ? (float) $balance->availableQuantity : 0,
                ];
            }
            $activeReservations = AssetReservation::where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouseId)
                ->where('material_id', $materialId)
                ->where('status', 'active')
                ->when(
                    array_key_exists('project_id', $metadata) && $metadata['project_id'] !== null,
                    static fn ($query) => $query->where('project_id', $metadata['project_id'])
                )
                ->orderBy('reserved_at')
                ->lockForUpdate()
                ->get();

            $totalReserved = (float) $activeReservations->sum('quantity');

            if ($totalReserved < $quantity) {
                throw new \InvalidArgumentException(
                    trans_message('warehouse_basic.validation.insufficient_reserved_stock', [
                        'reserved' => (float) $totalReserved,
                        'requested' => (float) $quantity,
                    ])
                );
            }

            $remainingToRelease = $quantity;
            $releasedReservationIds = [];
            foreach ($activeReservations as $reservation) {
                if ($remainingToRelease <= 0) {
                    break;
                }

                $takeFromReservation = min((float) $reservation->quantity, $remainingToRelease);
                if ((float) $reservation->quantity - $takeFromReservation <= 0.000001) {
                    $releasedReservationIds[] = $reservation->id;
                }
                $remainingToRelease -= $takeFromReservation;
            }
            $metadata['released_reservation_ids'] = $releasedReservationIds;

            $movement = $this->unreserveQuantity(
                $organizationId,
                $warehouseId,
                $materialId,
                $quantity,
                $metadata,
            );

            $remainingToRelease = $quantity;

            foreach ($activeReservations as $reservation) {
                if ($remainingToRelease <= 0) {
                    break;
                }

                $reservationQuantity = (float) $reservation->quantity;
                $takeFromReservation = min($reservationQuantity, $remainingToRelease);
                $remainingAfterRelease = $reservationQuantity - $takeFromReservation;
                $reservationMetadata = is_array($reservation->metadata) ? $reservation->metadata : [];

                $updatePayload = [
                    'quantity' => $remainingAfterRelease,
                    'metadata' => array_merge($reservationMetadata, [
                        'partial_release_history' => array_merge(
                            $reservationMetadata['partial_release_history'] ?? [],
                            [[
                                'released_quantity' => $takeFromReservation,
                                'released_at' => now()->toDateTimeString(),
                                'reason' => $metadata['reason'] ?? null,
                                'released_by' => $metadata['user_id'] ?? null,
                            ]]
                        ),
                    ]),
                ];

                if ($remainingAfterRelease <= 0.000001) {
                    $updatePayload['quantity'] = 0;
                    $updatePayload['status'] = 'cancelled';
                    $updatePayload['cancelled_at'] = now();
                }

                $reservation->update($updatePayload);

                $remainingToRelease -= $takeFromReservation;
            }

            $balance = $this->getAssetBalance($organizationId, $warehouseId, $materialId);

            $this->logging->business('warehouse.asset.unreserved.manual', [
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
                'quantity' => $quantity,
                'released_reservation_ids' => $releasedReservationIds,
                'user_id' => $metadata['user_id'] ?? null,
            ]);

            DB::commit();

            return [
                'released' => true,
                'quantity' => $quantity,
                'released_reservation_ids' => $releasedReservationIds,
                'remaining_reserved' => $balance ? (float) $balance->reservedQuantity : 0,
                'remaining_available' => $balance ? (float) $balance->availableQuantity : 0,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Создать правило автоматического пополнения
     */
    public function createAutoReorderRule(
        int $organizationId,
        int $materialId,
        array $ruleData
    ): array {
        $warehouseId = $ruleData['warehouse_id'];

        $rule = AutoReorderRule::query()->firstOrCreate(
            [
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
            ],
            [
                'organization_id' => $organizationId,
                'min_stock' => $ruleData['min_stock'],
                'max_stock' => $ruleData['max_stock'],
                'reorder_point' => $ruleData['reorder_point'],
                'reorder_quantity' => $ruleData['reorder_quantity'],
                'default_supplier_id' => $ruleData['default_supplier_id'] ?? null,
                'is_active' => $ruleData['is_active'] ?? true,
                'notes' => $ruleData['notes'] ?? null,
            ]
        );

        $action = $rule->wasRecentlyCreated ? 'created' : 'duplicate';

        $this->logging->business('warehouse.auto_reorder_rule.'.$action, [
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'rule_id' => $rule->id,
        ]);

        return [
            'rule_id' => $rule->id,
            'action' => $action,
            'material_id' => $materialId,
            'warehouse_id' => $warehouseId,
            'min_stock' => (float) $rule->min_stock,
            'max_stock' => (float) $rule->max_stock,
            'reorder_point' => (float) $rule->reorder_point,
            'reorder_quantity' => (float) $rule->reorder_quantity,
            'is_active' => $rule->is_active,
        ];
    }

    /**
     * Проверить необходимость автопополнения
     */
    public function checkAutoReorder(int $organizationId, ?int $warehouseId = null): array
    {
        $rules = AutoReorderRule::where('organization_id', $organizationId)
            ->where('is_active', true)
            ->when($warehouseId !== null, fn (Builder $query) => $query->where('warehouse_id', $warehouseId))
            ->with(['material.measurementUnit', 'warehouse.project', 'defaultSupplier'])
            ->get();

        $warehouses = new \Illuminate\Database\Eloquent\Collection(
            $rules->pluck('warehouse')->filter()->unique('id')->values()->all()
        );
        $this->applyReadableCustodyWarehouseNames($organizationId, $warehouses);

        $ordersToGenerate = [];
        $rulesChecked = 0;

        foreach ($rules as $rule) {
            $rulesChecked++;

            // Получаем текущий остаток (сумма по всем партиям)
            $currentStock = WarehouseBalance::where('organization_id', $organizationId)
                ->where('warehouse_id', $rule->warehouse_id)
                ->where('material_id', $rule->material_id)
                ->sum('available_quantity');

            // Проверяем нужно ли пополнение
            if ($rule->needsReorder($currentStock)) {
                $orderQuantity = $rule->calculateOrderQuantity($currentStock);

                $ordersToGenerate[] = [
                    'rule_id' => $rule->id,
                    'material_id' => $rule->material_id,
                    'material_name' => $rule->material->name,
                    'material_code' => $rule->material->code,
                    'measurement_unit' => $rule->material->measurementUnit?->short_name
                        ?? $rule->material->measurementUnit?->name
                        ?? trans_message('basic_warehouse.auto_reorder.measurement_unit_missing'),
                    'warehouse_id' => $rule->warehouse_id,
                    'warehouse_name' => $rule->warehouse->name,
                    'current_stock' => $currentStock,
                    'reorder_point' => (float) $rule->reorder_point,
                    'min_stock' => (float) $rule->min_stock,
                    'max_stock' => (float) $rule->max_stock,
                    'recommended_order_quantity' => $orderQuantity,
                    'supplier_id' => $rule->default_supplier_id,
                    'supplier_name' => $rule->defaultSupplier->name ?? null,
                    'priority' => $this->calculateOrderPriority($currentStock, $rule->reorder_point, $rule->min_stock),
                    'estimated_stock_out_days' => $this->estimateStockOutDays($organizationId, $rule->material_id, $currentStock),
                ];

                // Обновляем время последней проверки
                $rule->update(['last_checked_at' => now()]);
            } else {
                // Просто обновляем время проверки
                $rule->update(['last_checked_at' => now()]);
            }
        }

        // Сортируем по приоритету
        usort($ordersToGenerate, fn ($a, $b) => $b['priority'] <=> $a['priority']);

        $this->logging->business('warehouse.auto_reorder.checked', [
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouseId,
            'rules_checked' => $rulesChecked,
            'orders_to_generate' => count($ordersToGenerate),
        ]);

        return [
            'checked_at' => now()->toDateTimeString(),
            'rules_checked' => $rulesChecked,
            'orders_to_generate' => count($ordersToGenerate),
            'orders' => $ordersToGenerate,
            'summary' => [
                'critical_orders' => collect($ordersToGenerate)->where('priority', '>=', 8)->count(),
                'high_priority_orders' => collect($ordersToGenerate)->whereBetween('priority', [5, 7])->count(),
                'normal_orders' => collect($ordersToGenerate)->where('priority', '<', 5)->count(),
            ],
        ];
    }

    /**
     * Вспомогательный метод для расчета дисперсии
     */
    protected function calculateVariance(array $values): float
    {
        if (count($values) < 2) {
            return 0;
        }

        $mean = array_sum($values) / count($values);
        $variance = array_reduce($values, function ($carry, $value) use ($mean) {
            return $carry + pow($value - $mean, 2);
        }, 0) / count($values);

        return sqrt($variance) / ($mean > 0 ? $mean : 1);
    }

    /**
     * Получить рекомендацию по ABC/XYZ категории
     */
    protected function getAbcXyzRecommendation(string $category): string
    {
        $recommendations = [
            'AX' => 'Критический товар - строгий контроль запасов',
            'AY' => 'Критический товар - повышенные страховые запасы',
            'AZ' => 'Критический товар - максимальные страховые запасы',
            'BX' => 'Важный товар - стандартный контроль',
            'BY' => 'Важный товар - средние страховые запасы',
            'BZ' => 'Важный товар - повышенные страховые запасы',
            'CX' => 'Малоценный товар - упрощенный контроль',
            'CY' => 'Малоценный товар - стандартные запасы',
            'CZ' => 'Малоценный товар - минимальный контроль',
        ];

        return $recommendations[$category] ?? 'Требуется анализ';
    }

    /**
     * Рассчитать приоритет заказа (1-10)
     */
    protected function calculateOrderPriority(float $currentStock, float $reorderPoint, float $minStock): int
    {
        if ($currentStock <= 0) {
            return 10;
        }
        if ($currentStock < $minStock) {
            return 9;
        }
        if ($currentStock < $reorderPoint) {
            $ratio = ($reorderPoint - $currentStock) / ($reorderPoint - $minStock);

            return max(5, min(8, (int) (5 + $ratio * 3)));
        }

        return 3;
    }

    /**
     * Оценить количество дней до исчерпания запасов
     */
    protected function estimateStockOutDays(int $organizationId, int $materialId, float $currentStock): ?int
    {
        $movements = WarehouseMovement::where('organization_id', $organizationId)
            ->where('material_id', $materialId)
            ->where('movement_type', 'write_off')
            ->where('movement_date', '>=', now()->subDays(30))
            ->get();

        if ($movements->isEmpty()) {
            return null;
        }

        $totalConsumption = $movements->sum('quantity');
        $averageDailyConsumption = $totalConsumption / 30;

        if ($averageDailyConsumption <= 0) {
            return null;
        }

        return (int) ($currentStock / $averageDailyConsumption);
    }
}
