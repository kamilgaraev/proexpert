<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Exceptions\WarehouseCustodyIdempotencyConflictException;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\Models\Material;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

use function trans_message;

final class WarehouseCustodyService
{
    public function __construct(
        private readonly WarehouseService $warehouseService,
        private readonly ProjectWarehouseService $projectWarehouseService,
        private readonly WarehousePersonIdentityResolver $personIdentityResolver,
    ) {}

    public function getBalances(
        int $organizationId,
        ?int $projectId = null,
        ?int $responsibleUserId = null,
        ?int $materialId = null,
        ?string $search = null
    ): Collection {
        $search = trim((string) $search);

        $balances = WarehouseBalance::query()
            ->selectRaw(
                'MAX(id) AS id,
                organization_id,
                warehouse_id,
                material_id,
                SUM(available_quantity) AS available_quantity,
                SUM(reserved_quantity) AS reserved_quantity,
                CASE
                    WHEN SUM(available_quantity + reserved_quantity) > 0
                    THEN SUM((available_quantity + reserved_quantity) * unit_price)
                        / SUM(available_quantity + reserved_quantity)
                    ELSE 0
                END AS unit_price,
                MAX(last_movement_at) AS last_movement_at'
            )
            ->where('organization_id', $organizationId)
            ->where(static function ($query): void {
                $query->where('available_quantity', '>', 0)
                    ->orWhere('reserved_quantity', '>', 0);
            })
            ->whereHas('warehouse', static function ($query) use ($organizationId, $projectId, $responsibleUserId): void {
                $query->where('organization_id', $organizationId)
                    ->where('warehouse_type', OrganizationWarehouse::TYPE_CUSTODY)
                    ->where('is_active', true)
                    ->when($projectId !== null, static fn ($scope) => $scope->where('project_id', $projectId))
                    ->when($responsibleUserId !== null, static fn ($scope) => $scope->where('responsible_user_id', $responsibleUserId));
            })
            ->when($materialId !== null, static fn ($query) => $query->where('material_id', $materialId))
            ->when($search !== '', static function ($query) use ($search): void {
                $query->where(static function ($scope) use ($search): void {
                    $scope
                        ->whereHas('material', static function ($materialQuery) use ($search): void {
                            $materialQuery
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('code', 'like', "%{$search}%");
                        })
                        ->orWhereHas('warehouse.project', static function ($projectQuery) use ($search): void {
                            $projectQuery->where('name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('warehouse.responsibleUser', static function ($userQuery) use ($search): void {
                            $userQuery
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->with([
                'warehouse.project:id,name',
                'warehouse.responsibleUser:id,name,email',
                'material.measurementUnit:id,name,short_name',
            ])
            ->groupBy('organization_id', 'warehouse_id', 'material_id')
            ->orderByDesc('last_movement_at')
            ->orderByDesc('id')
            ->get();

        $references = $balances
            ->filter(static fn (WarehouseBalance $balance): bool => $balance->warehouse?->responsible_user_id !== null)
            ->mapWithKeys(static fn (WarehouseBalance $balance): array => [
                (int) $balance->id => [
                    'user_id' => (int) $balance->warehouse->responsible_user_id,
                    'date' => $balance->last_movement_at ?? now(),
                ],
            ])
            ->all();
        $identities = $this->personIdentityResolver->resolveMany($organizationId, $references);

        $balances->each(static function (WarehouseBalance $balance) use ($identities): void {
            $identity = $identities[(int) $balance->id] ?? null;
            if ($identity === null) {
                return;
            }

            $balance->setAttribute('responsible_user_display_name', $identity['name']);
            $balance->setAttribute('responsible_user_display_email', $identity['email']);
        });

        return $balances;
    }

    public function getSummary(
        int $organizationId,
        ?int $projectId = null,
        ?int $responsibleUserId = null,
        ?int $materialId = null,
        ?string $search = null
    ): array {
        $balances = $this->getBalances($organizationId, $projectId, $responsibleUserId, $materialId, $search);

        $rows = $balances
            ->groupBy(static fn (WarehouseBalance $balance): int => (int) $balance->warehouse?->responsible_user_id)
            ->filter(static fn (Collection $group, int $responsibleId): bool => $responsibleId > 0)
            ->map(function (Collection $group, int $responsibleId): array {
                $firstBalance = $group->first();

                $materials = $group
                    ->groupBy('material_id')
                    ->map(static function (Collection $materialGroup): array {
                        $materialBalance = $materialGroup->first();
                        $material = $materialBalance?->material;

                        return [
                            'material_id' => $material?->id,
                            'material_name' => $material?->name,
                            'measurement_unit' => $material?->measurementUnit?->short_name
                                ?? $material?->measurementUnit?->name,
                            'total_quantity' => round((float) $materialGroup->sum('available_quantity'), 4),
                            'positions_count' => $materialGroup->count(),
                            'projects_count' => $materialGroup
                                ->pluck('warehouse.project_id')
                                ->filter()
                                ->unique()
                                ->count(),
                        ];
                    })
                    ->values()
                    ->all();

                $lastMovementAt = $group
                    ->pluck('last_movement_at')
                    ->filter()
                    ->sortDesc()
                    ->first();

                return [
                    'responsible_user_id' => $responsibleId,
                    'responsible_user_name' => $firstBalance?->getAttribute('responsible_user_display_name'),
                    'responsible_user_email' => $firstBalance?->getAttribute('responsible_user_display_email'),
                    'total_quantity' => round((float) $group->sum('available_quantity'), 4),
                    'reserved_quantity' => round((float) $group->sum('reserved_quantity'), 4),
                    'positions_count' => $group->count(),
                    'materials_count' => $group->pluck('material_id')->unique()->count(),
                    'projects_count' => $group->pluck('warehouse.project_id')->filter()->unique()->count(),
                    'last_movement_at' => $lastMovementAt?->toDateTimeString(),
                    'materials' => $materials,
                ];
            })
            ->sortBy('responsible_user_name')
            ->values();

        return [
            'rows' => $rows->all(),
            'summary' => [
                'responsible_users_count' => $rows->count(),
                'positions_count' => $balances->count(),
                'materials_count' => $balances->pluck('material_id')->unique()->count(),
                'projects_count' => $balances->pluck('warehouse.project_id')->filter()->unique()->count(),
                'total_quantity' => round((float) $balances->sum('available_quantity'), 4),
                'reserved_quantity' => round((float) $balances->sum('reserved_quantity'), 4),
            ],
        ];
    }

    public function issueToResponsible(int $organizationId, User $actor, array $data): array
    {
        return DB::transaction(function () use ($organizationId, $actor, $data): array {
            $projectId = (int) $data['project_id'];
            $projectWarehouseId = (int) $data['project_warehouse_id'];
            $materialId = (int) $data['material_id'];
            $responsibleUserId = (int) $data['responsible_user_id'];
            $quantity = (float) $data['quantity'];
            $idempotencyKey = (string) $data['idempotency_key'];
            $fingerprint = WarehouseCustodyIdempotency::fingerprint(
                WarehouseMovement::CATEGORY_RESPONSIBLE_ISSUE,
                $data,
            );

            $project = $this->findProject($organizationId, $projectId);
            $this->lockProject($organizationId, $projectId);
            $this->findMaterial($organizationId, $materialId);
            $responsibleUser = $this->findResponsibleUser($organizationId, $responsibleUserId);
            $projectWarehouse = $this->findProjectWarehouse($organizationId, $projectId, $projectWarehouseId);
            $custodyWarehouse = $this->getOrCreateCustodyWarehouse(
                $organizationId,
                $project,
                $responsibleUser,
                $actor
            );
            $this->lockWarehouses($organizationId, [(int) $projectWarehouse->id, (int) $custodyWarehouse->id]);

            $replayed = $this->replayTransfer($organizationId, $idempotencyKey, $fingerprint);
            if ($replayed !== null) {
                return array_merge($replayed, [
                    'project_warehouse' => $projectWarehouse,
                    'custody_warehouse' => $this->warehouseService->withReadableWarehouseName($custodyWarehouse),
                ]);
            }

            $result = $this->warehouseService->transferAsset(
                $organizationId,
                (int) $projectWarehouse->id,
                (int) $custodyWarehouse->id,
                $materialId,
                $quantity,
                [
                    'project_id' => $projectId,
                    'user_id' => $actor->id,
                    'related_user_id' => $responsibleUserId,
                    'operation_category' => WarehouseMovement::CATEGORY_RESPONSIBLE_ISSUE,
                    'idempotency_key' => $idempotencyKey,
                    'custody_idempotency_fingerprint' => $fingerprint,
                    'transfer_pair_key' => $idempotencyKey,
                    'batch_number' => WarehouseCustodyLineage::batchNumber($idempotencyKey),
                    'document_number' => $data['document_number'] ?? null,
                    'reason' => $data['reason'] ?? trans_message('basic_warehouse.custody.issued'),
                ]
            );

            return array_merge($result, [
                'project_warehouse' => $projectWarehouse->refresh(),
                'custody_warehouse' => $this->warehouseService->withReadableWarehouseName($custodyWarehouse->refresh()),
            ]);
        });
    }

    public function returnFromResponsible(int $organizationId, User $actor, array $data): array
    {
        return DB::transaction(function () use ($organizationId, $actor, $data): array {
            $custodyWarehouse = OrganizationWarehouse::query()
                ->where('organization_id', $organizationId)
                ->where('warehouse_type', OrganizationWarehouse::TYPE_CUSTODY)
                ->where('is_active', true)
                ->findOrFail((int) $data['custody_warehouse_id']);

            if (! $custodyWarehouse->project_id || ! $custodyWarehouse->responsible_user_id) {
                throw new InvalidArgumentException(trans_message('basic_warehouse.custody.errors.invalid_custody_warehouse'));
            }

            $materialId = (int) $data['material_id'];
            $quantity = (float) $data['quantity'];
            $projectId = (int) $custodyWarehouse->project_id;
            $responsibleUserId = (int) $custodyWarehouse->responsible_user_id;
            $idempotencyKey = (string) $data['idempotency_key'];
            $fingerprint = WarehouseCustodyIdempotency::fingerprint(
                WarehouseMovement::CATEGORY_RESPONSIBLE_RETURN,
                $data,
            );

            $this->findMaterial($organizationId, $materialId);
            $this->lockProject($organizationId, $projectId);
            $projectWarehouse = $this->projectWarehouseService->getOrCreateProjectWarehouse(
                $organizationId,
                $projectId,
                $actor
            );
            $this->lockWarehouses($organizationId, [(int) $projectWarehouse->id, (int) $custodyWarehouse->id]);

            $replayed = $this->replayTransfer($organizationId, $idempotencyKey, $fingerprint);
            if ($replayed !== null) {
                return array_merge($replayed, [
                    'project_warehouse' => $projectWarehouse,
                    'custody_warehouse' => $this->warehouseService->withReadableWarehouseName($custodyWarehouse),
                ]);
            }

            $sourceIssueAllocations = $this->plannedSourceIssueAllocations(
                $organizationId,
                (int) $custodyWarehouse->id,
                $materialId,
                $quantity,
            );

            $result = $this->warehouseService->transferAsset(
                $organizationId,
                (int) $custodyWarehouse->id,
                (int) $projectWarehouse->id,
                $materialId,
                $quantity,
                [
                    'project_id' => $projectId,
                    'user_id' => $actor->id,
                    'related_user_id' => $responsibleUserId,
                    'operation_category' => WarehouseMovement::CATEGORY_RESPONSIBLE_RETURN,
                    'idempotency_key' => $idempotencyKey,
                    'custody_idempotency_fingerprint' => $fingerprint,
                    'transfer_pair_key' => $idempotencyKey,
                    'source_issue_allocations' => $sourceIssueAllocations,
                    'document_number' => $data['document_number'] ?? null,
                    'reason' => $data['reason'] ?? trans_message('basic_warehouse.custody.returned'),
                ]
            );

            return array_merge($result, [
                'project_warehouse' => $projectWarehouse->refresh(),
                'custody_warehouse' => $this->warehouseService->withReadableWarehouseName($custodyWarehouse->refresh()),
            ]);
        });
    }

    public function getOrCreateCustodyWarehouse(
        int $organizationId,
        Project $project,
        User $responsibleUser,
        User $actor
    ): OrganizationWarehouse {
        $warehouse = OrganizationWarehouse::withTrashed()
            ->where('organization_id', $organizationId)
            ->where('project_id', $project->id)
            ->where('responsible_user_id', $responsibleUser->id)
            ->where('warehouse_type', OrganizationWarehouse::TYPE_CUSTODY)
            ->first();

        if ($warehouse instanceof OrganizationWarehouse) {
            if ($warehouse->trashed()) {
                $warehouse->restore();
            }

            if (! $warehouse->is_active) {
                $warehouse->forceFill(['is_active' => true])->save();
            }

            return $this->warehouseService->withReadableWarehouseName($warehouse);
        }

        return OrganizationWarehouse::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $project->id,
            'responsible_user_id' => $responsibleUser->id,
            'name' => $this->warehouseService->custodyWarehouseName(
                $organizationId,
                $project,
                $responsibleUser,
                now()
            ),
            'code' => 'CUST-'.$project->id.'-'.$responsibleUser->id,
            'warehouse_type' => OrganizationWarehouse::TYPE_CUSTODY,
            'is_main' => false,
            'is_active' => true,
            'settings' => [
                'auto_created' => true,
                'created_by_user_id' => $actor->id,
            ],
        ]);
    }

    private function replayTransfer(int $organizationId, string $idempotencyKey, string $fingerprint): ?array
    {
        $movementOut = WarehouseMovement::query()
            ->where('organization_id', $organizationId)
            ->where('movement_type', WarehouseMovement::TYPE_TRANSFER_OUT)
            ->where('metadata->idempotency_key', $idempotencyKey)
            ->first();

        if (! $movementOut instanceof WarehouseMovement) {
            return null;
        }

        if (($movementOut->metadata['custody_idempotency_fingerprint'] ?? null) !== $fingerprint) {
            throw new WarehouseCustodyIdempotencyConflictException(
                trans_message('basic_warehouse.custody.errors.idempotency_conflict'),
            );
        }

        $movementIn = WarehouseMovement::query()
            ->where('organization_id', $organizationId)
            ->where('movement_type', WarehouseMovement::TYPE_TRANSFER_IN)
            ->where('metadata->transfer_pair_key', $idempotencyKey)
            ->firstOrFail();

        return [
            'movement_out' => $movementOut,
            'movement_in' => $movementIn,
            'avg_price' => (float) $movementOut->price,
            'source_details' => $movementOut->metadata['source_batches'] ?? [],
        ];
    }

    private function lockProject(int $organizationId, int $projectId): void
    {
        Project::query()
            ->where('organization_id', $organizationId)
            ->whereKey($projectId)
            ->lockForUpdate()
            ->firstOrFail();
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

    private function sourceIssueAllocations(int $organizationId, array $sourceDetails): array
    {
        $allocations = WarehouseCustodyLineage::allocations($sourceDetails);
        $keys = array_values(array_unique(array_column($allocations, 'idempotency_key')));
        $movementIds = WarehouseMovement::query()
            ->where('organization_id', $organizationId)
            ->where('movement_type', WarehouseMovement::TYPE_TRANSFER_IN)
            ->where('operation_category', WarehouseMovement::CATEGORY_RESPONSIBLE_ISSUE)
            ->whereIn('metadata->idempotency_key', $keys)
            ->get(['id', 'metadata'])
            ->mapWithKeys(static fn (WarehouseMovement $movement): array => [
                (string) ($movement->metadata['idempotency_key'] ?? '') => (int) $movement->id,
            ]);

        return array_map(static fn (array $allocation): array => [
            ...$allocation,
            'movement_id' => $movementIds->get($allocation['idempotency_key']),
        ], $allocations);
    }

    private function plannedSourceIssueAllocations(
        int $organizationId,
        int $warehouseId,
        int $materialId,
        float $quantity,
    ): array {
        $remaining = $quantity;
        $sourceDetails = [];
        $balances = WarehouseBalance::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->where('material_id', $materialId)
            ->where('available_quantity', '>', 0)
            ->orderByRaw('CASE WHEN expiry_date IS NOT NULL THEN expiry_date ELSE created_at END ASC')
            ->lockForUpdate()
            ->get();

        foreach ($balances as $balance) {
            if ($remaining <= 0) {
                break;
            }

            $allocated = min((float) $balance->available_quantity, $remaining);
            $sourceDetails[] = [
                'batch_number' => $balance->batch_number,
                'quantity' => $allocated,
            ];
            $remaining -= $allocated;
        }

        return $this->sourceIssueAllocations($organizationId, $sourceDetails);
    }

    private function findProject(int $organizationId, int $projectId): Project
    {
        return Project::query()
            ->where('organization_id', $organizationId)
            ->findOrFail($projectId);
    }

    private function findMaterial(int $organizationId, int $materialId): Material
    {
        return Material::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->findOrFail($materialId);
    }

    private function findResponsibleUser(int $organizationId, int $userId): User
    {
        $user = User::query()
            ->where('is_active', true)
            ->where(static function ($query) use ($organizationId): void {
                $query->where('current_organization_id', $organizationId)
                    ->orWhereHas('organizations', static function ($scope) use ($organizationId): void {
                        $scope->where('organizations.id', $organizationId)
                            ->where('organization_user.is_active', true);
                    });
            })
            ->findOrFail($userId);

        return $user;
    }

    private function findProjectWarehouse(
        int $organizationId,
        int $projectId,
        int $projectWarehouseId
    ): OrganizationWarehouse {
        $warehouse = OrganizationWarehouse::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_type', OrganizationWarehouse::TYPE_PROJECT)
            ->where('is_active', true)
            ->findOrFail($projectWarehouseId);

        if ((int) $warehouse->project_id !== $projectId) {
            throw new InvalidArgumentException(trans_message('basic_warehouse.custody.errors.project_warehouse_mismatch'));
        }

        return $warehouse;
    }
}
