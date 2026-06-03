<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

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
        private readonly ProjectWarehouseService $projectWarehouseService
    ) {
    }

    public function getBalances(
        int $organizationId,
        ?int $projectId = null,
        ?int $responsibleUserId = null,
        ?int $materialId = null
    ): Collection {
        return WarehouseBalance::query()
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
            ->with([
                'warehouse.project:id,name',
                'warehouse.responsibleUser:id,name,email',
                'material.measurementUnit:id,name,short_name',
            ])
            ->orderByDesc('last_movement_at')
            ->orderByDesc('id')
            ->get();
    }

    public function issueToResponsible(int $organizationId, User $actor, array $data): array
    {
        return DB::transaction(function () use ($organizationId, $actor, $data): array {
            $projectId = (int) $data['project_id'];
            $projectWarehouseId = (int) $data['project_warehouse_id'];
            $materialId = (int) $data['material_id'];
            $responsibleUserId = (int) $data['responsible_user_id'];
            $quantity = (float) $data['quantity'];

            $project = $this->findProject($organizationId, $projectId);
            $this->findMaterial($organizationId, $materialId);
            $responsibleUser = $this->findResponsibleUser($organizationId, $responsibleUserId);
            $projectWarehouse = $this->findProjectWarehouse($organizationId, $projectId, $projectWarehouseId);
            $custodyWarehouse = $this->getOrCreateCustodyWarehouse(
                $organizationId,
                $project,
                $responsibleUser,
                $actor
            );

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
                    'document_number' => $data['document_number'] ?? null,
                    'reason' => $data['reason'] ?? trans_message('basic_warehouse.custody.issued'),
                ]
            );

            $this->markTransferMovements(
                $result,
                WarehouseMovement::CATEGORY_RESPONSIBLE_ISSUE,
                $responsibleUserId
            );

            return array_merge($result, [
                'project_warehouse' => $projectWarehouse->refresh(),
                'custody_warehouse' => $custodyWarehouse->refresh(),
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

            if (!$custodyWarehouse->project_id || !$custodyWarehouse->responsible_user_id) {
                throw new InvalidArgumentException(trans_message('basic_warehouse.custody.errors.invalid_custody_warehouse'));
            }

            $materialId = (int) $data['material_id'];
            $quantity = (float) $data['quantity'];
            $projectId = (int) $custodyWarehouse->project_id;
            $responsibleUserId = (int) $custodyWarehouse->responsible_user_id;

            $this->findMaterial($organizationId, $materialId);
            $projectWarehouse = $this->projectWarehouseService->getOrCreateProjectWarehouse(
                $organizationId,
                $projectId,
                $actor
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
                    'document_number' => $data['document_number'] ?? null,
                    'reason' => $data['reason'] ?? trans_message('basic_warehouse.custody.returned'),
                ]
            );

            $this->markTransferMovements(
                $result,
                WarehouseMovement::CATEGORY_RESPONSIBLE_RETURN,
                $responsibleUserId
            );

            return array_merge($result, [
                'project_warehouse' => $projectWarehouse->refresh(),
                'custody_warehouse' => $custodyWarehouse->refresh(),
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

            if (!$warehouse->is_active) {
                $warehouse->forceFill(['is_active' => true])->save();
            }

            return $warehouse;
        }

        return OrganizationWarehouse::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $project->id,
            'responsible_user_id' => $responsibleUser->id,
            'name' => trans_message('basic_warehouse.custody.warehouse_name', [
                'project' => $project->name,
                'user' => $responsibleUser->name,
            ]),
            'code' => 'CUST-' . $project->id . '-' . $responsibleUser->id,
            'warehouse_type' => OrganizationWarehouse::TYPE_CUSTODY,
            'is_main' => false,
            'is_active' => true,
            'settings' => [
                'auto_created' => true,
                'created_by_user_id' => $actor->id,
            ],
        ]);
    }

    private function markTransferMovements(array $result, string $category, int $responsibleUserId): void
    {
        foreach (['movement_out', 'movement_in'] as $key) {
            if (!isset($result[$key]) || !$result[$key] instanceof WarehouseMovement) {
                continue;
            }

            $result[$key]->forceFill([
                'operation_category' => $category,
                'related_user_id' => $responsibleUserId,
            ])->save();
            $result[$key]->refresh();
        }
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
