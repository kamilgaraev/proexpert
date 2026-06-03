<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\BusinessModules\Features\BasicWarehouse\Enums\ProjectMaterialDeliveryStatusEnum;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BudgetEstimates\Services\ConstructionJournalPayloadService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use App\Enums\ConstructionJournal\JournalStatusEnum;
use App\Enums\EstimatePositionItemType;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use DomainException;

class MobileConstructionJournalService
{
    private const ACTIONS = [
        'view',
        'create',
        'update',
        'delete',
        'export',
        'create_entry',
        'submit',
        'approve',
        'reject',
        'export_daily_report',
    ];

    public function __construct(
        private readonly ConstructionJournalPayloadService $payloadService,
        private readonly AuthorizationService $authorizationService
    ) {
    }

    public function resolveProject(User $user, ?int $projectId): Project
    {
        $organizationId = (int) $user->current_organization_id;

        if ($organizationId <= 0) {
            throw new DomainException(trans_message('mobile_construction_journal.errors.no_organization'));
        }

        if ($projectId === null || $projectId <= 0) {
            throw new DomainException(trans_message('mobile_construction_journal.errors.project_not_found'));
        }

        $query = Project::query()
            ->where('organization_id', $organizationId)
            ->where('id', $projectId);

        if (!$user->isOrganizationAdmin($organizationId)) {
            $query->whereHas('users', function ($usersQuery) use ($user): void {
                $usersQuery->where('users.id', $user->id);
            });
        }

        $project = $query->first();

        if (!$project) {
            throw new DomainException(trans_message('mobile_construction_journal.errors.project_not_found'));
        }

        return $project;
    }

    public function assertJournalAccess(User $user, ConstructionJournal $journal): void
    {
        $projectId = (int) $journal->project_id;
        $this->resolveProject($user, $projectId);
    }

    public function buildJournalList(User $user, Project $project, int $page = 1, int $perPage = 15, ?string $status = null): array
    {
        $journals = $project->journals()
            ->with(['project', 'contract', 'createdBy'])
            ->withCount([
                'entries',
                'entries as approved_entries_count' => fn ($query) => $query->approved(),
                'entries as submitted_entries_count' => fn ($query) => $query->submitted(),
                'entries as rejected_entries_count' => fn ($query) => $query->rejected(),
            ])
            ->when($status, function ($query) use ($status): void {
                $query->where('status', $status);
            })
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            'items' => collect($journals->items())
                ->map(fn (ConstructionJournal $journal): array => $this->mapMobileJournal($journal, $user))
                ->values()
                ->all(),
            'meta' => $this->payloadService->paginationMeta($journals),
            'summary' => [
                'total_journals' => $journals->total(),
                'active_journals' => $project->journals()->where('status', 'active')->count(),
                'archived_journals' => $project->journals()->where('status', 'archived')->count(),
                'closed_journals' => $project->journals()->where('status', 'closed')->count(),
            ],
            'available_actions' => $this->mapActionList($this->payloadService->buildJournalActions($project, $user)),
        ];
    }

    public function buildEntriesList(User $user, ConstructionJournal $journal, array $filters): array
    {
        $query = $journal->entries()
            ->with([
                'journal',
                'scheduleTask',
                'estimate',
                'createdBy',
                'approvedBy',
                'workVolumes.estimateItem',
                'workVolumes.workType',
                'workVolumes.measurementUnit',
                'workers',
                'equipment',
                'materials.material',
            ]);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['date'])) {
            $query->whereDate('entry_date', $filters['date']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('entry_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('entry_date', '<=', $filters['date_to']);
        }

        $entries = $query->orderByDesc('entry_date')
            ->orderByDesc('entry_number')
            ->paginate((int) ($filters['per_page'] ?? 20), ['*'], 'page', (int) ($filters['page'] ?? 1));

        return [
            'items' => collect($entries->items())
                ->map(fn (ConstructionJournalEntry $entry): array => $this->mapMobileEntry($entry, $user))
                ->values()
                ->all(),
            'meta' => $this->payloadService->paginationMeta($entries),
            'summary' => $this->payloadService->buildEntrySummary($journal),
            'available_actions' => $this->mapActionList($this->payloadService->buildJournalActions($journal, $user)),
        ];
    }

    public function buildEntryFormOptions(User $user, ConstructionJournal $journal): array
    {
        $this->assertJournalAccess($user, $journal);

        $estimates = Estimate::query()
            ->where('organization_id', $journal->organization_id)
            ->where('project_id', $journal->project_id)
            ->with([
                'items' => function ($query): void {
                    $query->where('item_type', EstimatePositionItemType::WORK->value)
                        ->with([
                            'workType.measurementUnit',
                            'measurementUnit',
                            'contractLinks.contract.contractor',
                        ]);
                },
            ])
            ->orderByDesc('created_at')
            ->get();

        $workTypes = WorkType::query()
            ->where('organization_id', $journal->organization_id)
            ->where('is_active', true)
            ->with('measurementUnit')
            ->orderBy('name')
            ->get();

        return [
            'estimates' => $estimates
                ->map(fn (Estimate $estimate): array => $this->mapEstimateOption($estimate))
                ->values()
                ->all(),
            'work_types' => $workTypes
                ->map(fn (WorkType $workType): array => [
                    'id' => $workType->id,
                    'name' => $workType->name,
                    'measurement_unit_id' => $workType->measurement_unit_id,
                    'measurementUnit' => $workType->measurementUnit ? [
                        'id' => $workType->measurementUnit->id,
                        'name' => $workType->measurementUnit->name,
                        'short_name' => $workType->measurementUnit->short_name,
                    ] : null,
                ])
                ->values()
                ->all(),
            'project_materials' => $this->buildAcceptedProjectMaterials($user, (int) $journal->organization_id, (int) $journal->project_id),
        ];
    }

    public function mapMobileJournal(ConstructionJournal $journal, User $user, bool $includeEntries = false): array
    {
        return $this->transformJournalPayload($this->payloadService->mapJournal($journal, $user, $includeEntries));
    }

    public function mapMobileEntry(ConstructionJournalEntry $entry, User $user, bool $includeJournal = true): array
    {
        return $this->transformEntryPayload($this->payloadService->mapEntry($entry, $user, $includeJournal));
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

    private function mapEstimateOption(Estimate $estimate): array
    {
        return [
            'id' => $estimate->id,
            'name' => $estimate->name,
            'number' => $estimate->number,
            'items' => $estimate->items
                ->map(fn (EstimateItem $item): array => [
                    'id' => $item->id,
                    'estimate_id' => $item->estimate_id,
                    'position_number' => $item->position_number,
                    'name' => $item->name,
                    'item_type' => $item->item_type?->value,
                    'quantity' => $this->requiredEstimateQuantity($item, 'quantity'),
                    'quantity_total' => $this->requiredEstimateQuantity($item, 'quantity_total'),
                    'work_type_id' => $item->work_type_id,
                    'measurement_unit_id' => $item->measurement_unit_id,
                    'workType' => $item->workType ? [
                        'id' => $item->workType->id,
                        'name' => $item->workType->name,
                        'measurement_unit_id' => $item->workType->measurement_unit_id,
                        'measurementUnit' => $item->workType->measurementUnit ? [
                            'id' => $item->workType->measurementUnit->id,
                            'name' => $item->workType->measurementUnit->name,
                            'short_name' => $item->workType->measurementUnit->short_name,
                        ] : null,
                    ] : null,
                    'measurementUnit' => $item->measurementUnit ? [
                        'id' => $item->measurementUnit->id,
                        'name' => $item->measurementUnit->name,
                        'short_name' => $item->measurementUnit->short_name,
                    ] : null,
                    'contract_links' => $item->contractLinks
                        ->map(fn ($link): array => [
                            'contract_id' => $link->contract_id,
                            'contract_number' => $link->contract?->number,
                            'contractor_name' => $link->contract?->contractor?->name,
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    private function transformJournalPayload(array $payload): array
    {
        $status = JournalStatusEnum::tryFrom($this->requiredPayloadString($payload, 'status'));

        if (!$status) {
            throw new DomainException(trans_message('mobile_construction_journal.errors.invalid_status'));
        }

        $payload['status'] = $status->value;
        $payload['status_label'] = trans_message('mobile_construction_journal.statuses.journal.' . $status->value);
        $payload['available_actions'] = $this->mapActionList($this->requiredPayloadArray($payload, 'available_actions'));

        if (isset($payload['entries']) && is_array($payload['entries'])) {
            $payload['entries'] = collect($payload['entries'])
                ->map(fn (array $entry): array => $this->transformEntryPayload($entry))
                ->values()
                ->all();
        }

        return $payload;
    }

    private function transformEntryPayload(array $payload): array
    {
        $status = JournalEntryStatusEnum::tryFrom($this->requiredPayloadString($payload, 'status'));

        if (!$status) {
            throw new DomainException(trans_message('mobile_construction_journal.errors.invalid_status'));
        }

        $payload['status'] = $status->value;
        $payload['status_label'] = trans_message('mobile_construction_journal.statuses.entry.' . $status->value);
        $payload['available_actions'] = $this->mapActionList($this->requiredPayloadArray($payload, 'available_actions'));

        if (isset($payload['journal']) && is_array($payload['journal'])) {
            $payload['journal'] = $this->transformJournalPayload($payload['journal']);
        }

        $payload['workVolumes'] = collect($this->requiredPayloadArray($payload, 'workVolumes'))
            ->map(fn (array $volume): array => $this->transformWorkVolumePayload($volume))
            ->values()
            ->all();

        return $payload;
    }

    private function transformWorkVolumePayload(array $volume): array
    {
        $title = trim((string) ($volume['estimateItem']['name'] ?? $volume['workType']['name'] ?? ''));
        $measurementUnitName = trim((string) (
            $volume['measurementUnit']['short_name']
            ?? $volume['measurementUnit']['name']
            ?? ''
        ));

        if ($title === '') {
            throw new DomainException(trans_message('mobile_construction_journal.errors.work_volume_title_missing'));
        }

        if ($measurementUnitName === '') {
            throw new DomainException(trans_message('mobile_construction_journal.errors.work_volume_measurement_unit_missing'));
        }

        $volume['title'] = $title;
        $volume['measurement_unit_name'] = $measurementUnitName;

        return $volume;
    }

    private function mapActionList(array $actions): array
    {
        return collect($actions)
            ->map(function (mixed $action): array {
                $key = (string) $action;

                if (!in_array($key, self::ACTIONS, true)) {
                    throw new DomainException(trans_message('mobile_construction_journal.errors.invalid_action'));
                }

                return [
                    'action' => $key,
                    'label' => trans_message('mobile_construction_journal.actions.' . $key),
                ];
            })
            ->values()
            ->all();
    }

    private function mapProjectMaterialOption(
        ProjectMaterialDelivery $delivery,
        int $organizationId,
        ?OrganizationWarehouse $custodyWarehouse,
        bool $canIssueFromProject
    ): array
    {
        $material = $delivery->material;
        $measurementUnit = $material?->measurementUnit;

        if (!$material) {
            throw new DomainException(trans_message('mobile_construction_journal.errors.material_missing'));
        }

        if (!$measurementUnit) {
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

    private function requiredEstimateQuantity(EstimateItem $item, string $attribute): float
    {
        $value = $item->getAttribute($attribute);

        if ($value === null) {
            throw new DomainException(trans_message('mobile_construction_journal.errors.estimate_quantity_missing'));
        }

        return (float) $value;
    }

    private function requiredPayloadString(array $payload, string $key): string
    {
        $value = trim((string) ($payload[$key] ?? ''));

        if ($value === '') {
            throw new DomainException(trans_message('mobile_construction_journal.errors.invalid_status'));
        }

        return $value;
    }

    private function requiredPayloadArray(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;

        if (!is_array($value)) {
            throw new DomainException(trans_message('mobile_construction_journal.errors.incomplete_payload'));
        }

        return $value;
    }
}
