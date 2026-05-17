<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\BusinessModules\Features\BasicWarehouse\Enums\ProjectMaterialDeliveryStatusEnum;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BudgetEstimates\Services\ConstructionJournalPayloadService;
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
    public function __construct(
        private readonly ConstructionJournalPayloadService $payloadService
    ) {
    }

    public function resolveProject(User $user, ?int $projectId): Project
    {
        $organizationId = (int) $user->current_organization_id;

        if ($organizationId <= 0) {
            throw new DomainException(trans_message('mobile_construction_journal.errors.no_organization'));
        }

        if (($projectId ?? 0) <= 0) {
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
                ->map(fn (ConstructionJournal $journal): array => $this->payloadService->mapJournal($journal, $user))
                ->values()
                ->all(),
            'meta' => $this->payloadService->paginationMeta($journals),
            'summary' => [
                'total_journals' => $journals->total(),
                'active_journals' => $project->journals()->where('status', 'active')->count(),
                'archived_journals' => $project->journals()->where('status', 'archived')->count(),
                'closed_journals' => $project->journals()->where('status', 'closed')->count(),
            ],
            'available_actions' => $this->payloadService->buildJournalActions($project, $user),
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
                ->map(fn (ConstructionJournalEntry $entry): array => $this->payloadService->mapEntry($entry, $user))
                ->values()
                ->all(),
            'meta' => $this->payloadService->paginationMeta($entries),
            'summary' => $this->payloadService->buildEntrySummary($journal),
            'available_actions' => $this->payloadService->buildJournalActions($journal, $user),
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
            'project_materials' => $this->buildAcceptedProjectMaterials((int) $journal->organization_id, (int) $journal->project_id),
        ];
    }

    private function buildAcceptedProjectMaterials(int $organizationId, int $projectId): array
    {
        return ProjectMaterialDelivery::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('status', ProjectMaterialDeliveryStatusEnum::ACCEPTED->value)
            ->where('accepted_quantity', '>', 0)
            ->with(['material.measurementUnit', 'allocation'])
            ->orderByDesc('accepted_at')
            ->get()
            ->map(static fn (ProjectMaterialDelivery $delivery): array => [
                'material_id' => $delivery->material_id,
                'project_material_delivery_id' => $delivery->id,
                'warehouse_project_allocation_id' => $delivery->warehouse_project_allocation_id,
                'name' => $delivery->material?->name,
                'code' => $delivery->material?->code,
                'available_quantity' => (float) $delivery->accepted_quantity,
                'measurement_unit' => $delivery->material?->measurementUnit ? [
                    'id' => $delivery->material->measurementUnit->id,
                    'name' => $delivery->material->measurementUnit->name,
                    'short_name' => $delivery->material->measurementUnit->short_name,
                ] : null,
                'accepted_at' => $delivery->accepted_at?->toDateTimeString(),
            ])
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
                    'quantity' => (float) ($item->quantity ?? $item->quantity_total ?? 0),
                    'quantity_total' => (float) ($item->quantity_total ?? $item->quantity ?? 0),
                    'work_type_id' => $item->work_type_id,
                    'measurement_unit_id' => $item->measurement_unit_id,
                    'workType' => $item->workType ? [
                        'id' => $item->workType->id,
                        'name' => $item->workType->name,
                        'measurement_unit_id' => $item->workType->measurement_unit_id,
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
}
