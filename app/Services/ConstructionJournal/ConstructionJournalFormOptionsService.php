<?php

declare(strict_types=1);

namespace App\Services\ConstructionJournal;

use App\BusinessModules\Features\BasicWarehouse\Enums\ProjectMaterialDeliveryStatusEnum;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BudgetEstimates\Services\JournalContractCoverageService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\EstimatePositionItemType;
use App\Models\ConstructionJournal;
use App\Models\Contract;
use App\Models\Project;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateItemResource;
use App\Models\MeasurementUnit;
use App\Models\User;
use App\Models\WorkType;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;

class ConstructionJournalFormOptionsService
{
    public function __construct(
        private readonly ConstructionJournalAccessService $access,
        private readonly JournalContractCoverageService $coverage,
        private readonly AuthorizationService $authorizationService,
    ) {}

    public function buildJournalOptions(User $user, int $projectId, ?int $journalId = null): array
    {
        $project = Project::query()->find($projectId);
        if (! $project) {
            throw new AuthorizationException(trans_message('errors.unauthorized'));
        }

        if ($journalId !== null) {
            $journal = ConstructionJournal::query()->find($journalId);
            if (! $journal || (int) $journal->project_id !== (int) $project->id
                || (int) $journal->organization_id !== (int) $project->organization_id
                || ! $journal->canBeEdited()
                || ! $this->access->canWrite($user, $journal, ['edit', '*'])) {
                throw new AuthorizationException(trans_message('errors.unauthorized'));
            }

            return $this->buildProjectContractOptions($project);
        }

        return $this->buildJournalFormOptions($user, $project);
    }

    public function buildJournalFormOptions(User $user, Project $project): array
    {
        if (! $this->access->canAccessProject($user, $project)
            || ! $this->access->hasPermission($user, $project, ['create', '*'])) {
            throw new AuthorizationException(trans_message('errors.unauthorized'));
        }

        return $this->buildProjectContractOptions($project);
    }

    private function buildProjectContractOptions(Project $project): array
    {
        $contracts = Contract::query()
            ->where('organization_id', $project->organization_id)
            ->where(function ($query) use ($project): void {
                $query->where('project_id', $project->id)
                    ->orWhereHas('projects', static function ($projectsQuery) use ($project): void {
                        $projectsQuery->where('projects.id', $project->id);
                    });
            })
            ->whereIn('status', ['active', 'completed'])
            ->with('contractor:id,name')
            ->orderBy('number')
            ->get();

        return [
            'contracts' => $contracts->map(static fn (Contract $contract): array => [
                'id' => $contract->id,
                'number' => $contract->number,
                'contractor_name' => $contract->contractor?->name,
                'status' => $contract->status instanceof \BackedEnum
                    ? $contract->status->value
                    : (string) $contract->status,
            ])->values()->all(),
        ];
    }

    public function build(User $user, ConstructionJournal $journal): array
    {
        $this->access->assertReadable($user, $journal);

        $estimates = Estimate::query()
            ->where('organization_id', $journal->organization_id)
            ->where('project_id', $journal->project_id)
            ->where('status', 'approved')
            ->with(['items' => function ($query): void {
                $query->where('item_type', EstimatePositionItemType::WORK->value)
                    ->with(self::estimateItemRelations());
            }])
            ->orderByDesc('created_at')
            ->get();
        $workTypes = WorkType::query()
            ->where(function ($query) use ($journal): void {
                $query->where('organization_id', $journal->organization_id)->orWhereNull('organization_id');
            })
            ->where('is_active', true)
            ->with('measurementUnit')
            ->orderBy('name')
            ->get();

        return [
            'estimates' => $estimates->map(fn (Estimate $estimate): array => [
                'id' => $estimate->id,
                'name' => $estimate->name,
                'number' => $estimate->number,
                'items' => $estimate->items->map(fn (EstimateItem $item): array => $this->mapEstimateItem($item, $journal))->values()->all(),
            ])->values()->all(),
            'work_types' => $workTypes->map(fn (WorkType $workType): array => $this->mapWorkType($workType))->values()->all(),
            'measurement_units' => MeasurementUnit::query()
                ->where(function ($query) use ($journal): void {
                    $query->where('organization_id', $journal->organization_id)->orWhereNull('organization_id');
                })
                ->orderBy('name')
                ->get()
                ->map(fn (MeasurementUnit $unit): array => $this->mapMeasurementUnit($unit))
                ->values()->all(),
            'project_materials' => (int) $user->current_organization_id === (int) $journal->organization_id
                ? $this->buildAcceptedProjectMaterials($user, (int) $journal->organization_id, (int) $journal->project_id)
                : [],
        ];
    }

    public static function estimateItemRelations(): array
    {
        return ['workType.measurementUnit', 'measurementUnit', 'contractLinks.contract.contractor', 'resources.material.measurementUnit', 'resources.measurementUnit'];
    }

    public function mapEstimateItem(EstimateItem $item, ConstructionJournal $journal): array
    {
        $item->loadMissing(self::estimateItemRelations());
        $currentContractLink = $journal->contract_id
            ? $item->contractLinks->firstWhere('contract_id', $journal->contract_id)
            : null;
        $plannedValue = $item->quantity_total ?? $item->quantity;
        $plannedQuantity = $plannedValue === null ? null : (float) $plannedValue;

        return [
            'id' => $item->id,
            'estimate_id' => $item->estimate_id,
            'position_number' => $item->position_number,
            'name' => $item->name,
            'item_type' => $item->item_type?->value,
            'quantity' => $item->quantity === null ? null : (float) $item->quantity,
            'quantity_total' => $item->quantity_total === null ? $plannedQuantity : (float) $item->quantity_total,
            'estimate_planned_quantity' => $plannedQuantity,
            'contract_agreed_quantity' => $currentContractLink?->quantity === null ? null : (float) $currentContractLink->quantity,
            'work_type_id' => $item->work_type_id,
            'measurement_unit_id' => $item->measurement_unit_id,
            'workType' => $item->workType ? $this->mapWorkType($item->workType) : null,
            'measurementUnit' => $this->mapMeasurementUnit($item->measurementUnit),
            'contract_links' => $item->contractLinks->map(static fn ($link): array => [
                'contract_id' => $link->contract_id,
                'contract_number' => $link->contract?->number,
                'contractor_name' => $link->contract?->contractor?->name,
            ])->values()->all(),
            'contract_coverage' => $this->coverage->resolve($journal, $item),
            'resources' => $item->resources->map(fn (EstimateItemResource $resource): array => [
                'id' => $resource->id,
                'resource_type' => $resource->resource_type,
                'name' => $resource->name ?: $resource->material?->name,
                'measurement_unit_id' => $resource->measurement_unit_id,
                'measurementUnit' => $this->mapMeasurementUnit($resource->measurementUnit),
                'quantity_per_unit' => (float) $resource->quantity_per_unit,
                'total_quantity' => (float) $resource->total_quantity,
                'estimate_item_id' => $resource->represented_by_item_id,
                'material_id' => $resource->material_id,
            ])->values()->all(),
        ];
    }

    private function mapWorkType(WorkType $workType): array
    {
        return [
            'id' => $workType->id,
            'name' => $workType->name,
            'measurement_unit_id' => $workType->measurement_unit_id,
            'measurementUnit' => $this->mapMeasurementUnit($workType->measurementUnit),
        ];
    }

    private function mapMeasurementUnit(?MeasurementUnit $unit): ?array
    {
        return $unit ? ['id' => $unit->id, 'name' => $unit->name, 'short_name' => $unit->short_name] : null;
    }

    private function buildAcceptedProjectMaterials(User $user, int $organizationId, int $projectId): array
    {
        $custodyWarehouse = $this->resolveResponsibleCustodyWarehouse($organizationId, $projectId, (int) $user->id);
        $canIssueFromProject = $this->authorizationService->can($user, 'warehouse.manage_stock', [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
        ]);

        return ProjectMaterialDelivery::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->whereHas('material', static fn ($query) => $query->where('organization_id', $organizationId))
            ->where('status', ProjectMaterialDeliveryStatusEnum::ACCEPTED->value)
            ->where('accepted_quantity', '>', 0)
            ->with(['material.measurementUnit', 'allocation'])
            ->orderByDesc('accepted_at')
            ->get()
            ->filter(function (ProjectMaterialDelivery $delivery) use ($organizationId, $custodyWarehouse, $canIssueFromProject): bool {
                if ($delivery->availableQuantity() <= 0) {
                    return false;
                }

                $custodyQuantity = $custodyWarehouse
                    ? $this->availableWarehouseQuantity($organizationId, (int) $custodyWarehouse->id, (int) $delivery->material_id)
                    : 0.0;
                $projectQuantity = $canIssueFromProject && $delivery->project_warehouse_id
                    ? $this->availableWarehouseQuantity($organizationId, (int) $delivery->project_warehouse_id, (int) $delivery->material_id)
                    : 0.0;

                return $custodyQuantity > 0 || $projectQuantity > 0;
            })
            ->map(fn (ProjectMaterialDelivery $delivery): array => $this->mapProjectMaterialOption(
                $delivery,
                $organizationId,
                $custodyWarehouse,
                $canIssueFromProject
            ))
            ->values()
            ->all();
    }

    private function mapProjectMaterialOption(
        ProjectMaterialDelivery $delivery,
        int $organizationId,
        ?OrganizationWarehouse $custodyWarehouse,
        bool $canIssueFromProject
    ): array {
        $material = $delivery->material;
        $measurementUnit = $material?->measurementUnit;

        if (! $material) {
            throw new DomainException(trans_message('mobile_construction_journal.errors.material_missing'));
        }

        if (! $measurementUnit) {
            throw new DomainException(trans_message('mobile_construction_journal.errors.material_measurement_unit_missing'));
        }

        $deliveryAvailableQuantity = $delivery->availableQuantity();
        $custodyAvailableQuantity = $custodyWarehouse
            ? $this->availableWarehouseQuantity($organizationId, (int) $custodyWarehouse->id, (int) $delivery->material_id)
            : 0.0;
        $projectWarehouseAvailableQuantity = $canIssueFromProject && $delivery->project_warehouse_id
            ? $this->availableWarehouseQuantity($organizationId, (int) $delivery->project_warehouse_id, (int) $delivery->material_id)
            : 0.0;
        $availableQuantity = min($deliveryAvailableQuantity, $custodyAvailableQuantity);

        return [
            'material_id' => $delivery->material_id,
            'delivery_id' => $delivery->id,
            'project_material_delivery_id' => $delivery->id,
            'warehouse_project_allocation_id' => $delivery->warehouse_project_allocation_id,
            'name' => $material->name,
            'code' => $material->code,
            'accepted_quantity' => (float) $delivery->accepted_quantity,
            'used_quantity' => $delivery->usedQuantity(),
            'available_quantity' => $availableQuantity,
            'custody_warehouse_id' => $custodyWarehouse?->id,
            'custody_available_quantity' => $custodyAvailableQuantity,
            'can_consume_from_custody' => $custodyAvailableQuantity > 0,
            'project_warehouse_id' => $delivery->project_warehouse_id,
            'project_warehouse_available_quantity' => $projectWarehouseAvailableQuantity,
            'can_issue_from_project' => $canIssueFromProject && $projectWarehouseAvailableQuantity > 0,
            'requires_issue_from_project' => $availableQuantity <= 0 && $canIssueFromProject && $projectWarehouseAvailableQuantity > 0,
            'measurement_unit' => [
                'id' => $measurementUnit->id,
                'name' => $measurementUnit->name,
                'short_name' => $measurementUnit->short_name,
            ],
            'accepted_at' => $delivery->accepted_at?->toDateTimeString(),
        ];
    }

    private function resolveResponsibleCustodyWarehouse(
        int $organizationId,
        int $projectId,
        int $responsibleUserId
    ): ?OrganizationWarehouse {
        return OrganizationWarehouse::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('responsible_user_id', $responsibleUserId)
            ->where('warehouse_type', OrganizationWarehouse::TYPE_CUSTODY)
            ->where('is_active', true)
            ->first();
    }

    private function availableWarehouseQuantity(int $organizationId, int $warehouseId, int $materialId): float
    {
        return (float) WarehouseBalance::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->where('material_id', $materialId)
            ->sum('available_quantity');
    }

}
