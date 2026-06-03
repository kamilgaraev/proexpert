<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\BusinessModules\Features\BasicWarehouse\Enums\ProjectMaterialDeliveryStatusEnum;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService;
use BackedEnum;
use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use App\Enums\ConstructionJournal\JournalStatusEnum;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Contract;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\ScheduleTask;
use App\Models\User;
use App\Models\WorkType;
use App\Services\CompletedWork\CompletedWorkFactService;
use App\Services\Logging\LoggingService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ConstructionJournalService
{
    public function __construct(
        private readonly CompletedWorkFactService $completedWorkFactService,
        private readonly JournalContractCoverageService $journalContractCoverageService,
        private readonly LoggingService $logging,
        private readonly WarehouseService $warehouseService,
    ) {
    }

    public function createJournal(Project $project, array $data, User $user): ConstructionJournal
    {
        return DB::transaction(function () use ($project, $data, $user): ConstructionJournal {
            $this->assertContractScope($project, $data['contract_id'] ?? null);

            $journal = ConstructionJournal::create([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'contract_id' => $data['contract_id'] ?? null,
                'name' => $data['name'],
                'journal_number' => $data['journal_number'] ?? $this->generateJournalNumber($project),
                'start_date' => $data['start_date'] ?? now(),
                'end_date' => $data['end_date'] ?? null,
                'status' => $data['status'] ?? JournalStatusEnum::ACTIVE,
                'created_by_user_id' => $user->id,
            ]);

            $journal->load(['project', 'contract', 'createdBy']);
            $this->recordJournalAudit('construction_journal.created', $journal, $user);

            return $journal;
        });
    }

    public function updateJournal(ConstructionJournal $journal, array $data): ConstructionJournal
    {
        if (array_key_exists('contract_id', $data)) {
            $this->assertContractScope($journal->project, $data['contract_id']);
        }

        $journal->update($data);

        $journal = $journal->fresh(['project', 'contract', 'createdBy']);
        $this->recordJournalAudit('construction_journal.updated', $journal);

        return $journal;
    }

    public function deleteJournal(ConstructionJournal $journal): bool
    {
        return DB::transaction(function () use ($journal): bool {
            $deleted = (bool) $journal->delete();
            $this->recordJournalAudit('construction_journal.deleted', $journal);

            return $deleted;
        });
    }

    public function createEntry(ConstructionJournal $journal, array $data, User $user): ConstructionJournalEntry
    {
        return DB::transaction(function () use ($journal, $data, $user): ConstructionJournalEntry {
            $this->assertEntryScope($journal, $data, null, $user);

            $entryNumber = $data['entry_number'] ?? $journal->getNextEntryNumber();

            $entry = ConstructionJournalEntry::create([
                'journal_id' => $journal->id,
                'schedule_task_id' => $data['schedule_task_id'] ?? null,
                'estimate_id' => $data['estimate_id'] ?? null,
                'entry_date' => $data['entry_date'],
                'entry_number' => $entryNumber,
                'work_description' => $data['work_description'],
                'status' => $data['status'] ?? JournalEntryStatusEnum::DRAFT,
                'created_by_user_id' => $user->id,
                'weather_conditions' => $data['weather_conditions'] ?? null,
                'problems_description' => $data['problems_description'] ?? null,
                'safety_notes' => $data['safety_notes'] ?? null,
                'visitors_notes' => $data['visitors_notes'] ?? null,
                'quality_notes' => $data['quality_notes'] ?? null,
            ]);

            if (isset($data['work_volumes']) && is_array($data['work_volumes'])) {
                $this->attachWorkVolumes($entry, $data['work_volumes']);
            }

            if (isset($data['workers']) && is_array($data['workers'])) {
                $this->attachWorkers($entry, $data['workers']);
            }

            if (isset($data['equipment']) && is_array($data['equipment'])) {
                $this->attachEquipment($entry, $data['equipment']);
            }

            if (isset($data['materials']) && is_array($data['materials'])) {
                $this->attachMaterials($entry, $data['materials']);
            }

            $this->completedWorkFactService->syncFromJournalEntry($entry->load([
                'journal',
                'journal.contract',
                'scheduleTask.estimateItem.contractLinks.contract.contractor',
                'workVolumes.estimateItem.contractLinks.contract.contractor',
                'workVolumes.workType',
                'materials.estimateItem.contractLinks.contract.contractor',
                'equipment.estimateItem.contractLinks.contract.contractor',
                'workers.estimateItem.contractLinks.contract.contractor',
            ]));

            $entry->load([
                'journal',
                'scheduleTask',
                'estimate',
                'createdBy',
                'completedWorks',
                'workVolumes.estimateItem.contractLinks.contract.contractor',
                'workVolumes.workType',
                'workVolumes.measurementUnit',
                'workers.estimateItem',
                'equipment.estimateItem',
                'materials.material',
                'materials.estimateItem',
            ]);

            $this->recordEntryAudit('construction_journal_entry.created', $entry, $user);

            return $entry;
        });
    }

    public function updateEntry(ConstructionJournalEntry $entry, array $data): ConstructionJournalEntry
    {
        return DB::transaction(function () use ($entry, $data): ConstructionJournalEntry {
            $this->assertEntryScope($entry->journal, $data, $entry);

            if (array_key_exists('materials', $data)) {
                $this->assertJournalMaterialConsumptionCanBeReplaced($entry);
            }

            $updateData = [];

            if (array_key_exists('schedule_task_id', $data)) {
                $updateData['schedule_task_id'] = $data['schedule_task_id'];
            }

            if (array_key_exists('estimate_id', $data)) {
                $updateData['estimate_id'] = $data['estimate_id'];
            }

            if (array_key_exists('entry_date', $data)) {
                $updateData['entry_date'] = $data['entry_date'];
            }

            if (array_key_exists('work_description', $data)) {
                $updateData['work_description'] = $data['work_description'];
            }

            if (array_key_exists('weather_conditions', $data)) {
                $updateData['weather_conditions'] = $data['weather_conditions'];
            }

            if (array_key_exists('problems_description', $data)) {
                $updateData['problems_description'] = $data['problems_description'];
            }

            if (array_key_exists('safety_notes', $data)) {
                $updateData['safety_notes'] = $data['safety_notes'];
            }

            if (array_key_exists('visitors_notes', $data)) {
                $updateData['visitors_notes'] = $data['visitors_notes'];
            }

            if (array_key_exists('quality_notes', $data)) {
                $updateData['quality_notes'] = $data['quality_notes'];
            }

            if ($updateData !== []) {
                $entry->update($updateData);
            }

            if (array_key_exists('work_volumes', $data)) {
                $this->syncWorkVolumes($entry, $data['work_volumes'] ?? []);
            }

            if (array_key_exists('workers', $data)) {
                $entry->workers()->delete();
                $this->attachWorkers($entry, $data['workers'] ?? []);
            }

            if (array_key_exists('equipment', $data)) {
                $entry->equipment()->delete();
                $this->attachEquipment($entry, $data['equipment'] ?? []);
            }

            if (array_key_exists('materials', $data)) {
                $entry->materials()->delete();
                $this->attachMaterials($entry, $data['materials'] ?? []);
            }

            $this->completedWorkFactService->syncFromJournalEntry($entry->load([
                'journal',
                'journal.contract',
                'scheduleTask.estimateItem.contractLinks.contract.contractor',
                'workVolumes.estimateItem.contractLinks.contract.contractor',
                'workVolumes.workType',
                'materials.estimateItem.contractLinks.contract.contractor',
                'equipment.estimateItem.contractLinks.contract.contractor',
                'workers.estimateItem.contractLinks.contract.contractor',
            ]));

            $entry = $entry->fresh([
                'journal',
                'scheduleTask',
                'estimate',
                'createdBy',
                'approvedBy',
                'completedWorks',
                'workVolumes.estimateItem.contractLinks.contract.contractor',
                'workVolumes.workType',
                'workVolumes.measurementUnit',
                'workers.estimateItem',
                'equipment.estimateItem',
                'materials.material',
                'materials.estimateItem',
            ]);

            $this->recordEntryAudit('construction_journal_entry.updated', $entry);

            return $entry;
        });
    }

    public function deleteEntry(ConstructionJournalEntry $entry): bool
    {
        return DB::transaction(function () use ($entry): bool {
            $entry->loadMissing('journal');
            $this->completedWorkFactService->deleteJournalEntryFacts($entry);
            $deleted = (bool) $entry->delete();
            $this->recordEntryAudit('construction_journal_entry.deleted', $entry);

            return $deleted;
        });
    }

    public function getDailyEntries(ConstructionJournal $journal, Carbon $date): Collection
    {
        return $journal->entries()
            ->byDate($date)
            ->with([
                'scheduleTask',
                'estimate',
                'createdBy',
                'approvedBy',
                'completedWorks',
                'workVolumes.estimateItem',
                'workers.estimateItem',
                'equipment.estimateItem',
                'materials.material',
                'materials.estimateItem',
            ])
            ->get();
    }

    public function getEntriesForPeriod(ConstructionJournal $journal, Carbon $from, Carbon $to): Collection
    {
        return $journal->entries()
            ->byDateRange($from, $to)
            ->with([
                'scheduleTask',
                'estimate',
                'createdBy',
                'approvedBy',
                'completedWorks',
                'workVolumes.estimateItem',
                'workers.estimateItem',
                'equipment.estimateItem',
                'materials.material',
                'materials.estimateItem',
            ])
            ->get();
    }

    protected function attachWorkVolumes(ConstructionJournalEntry $entry, array $volumes): void
    {
        $entry->loadMissing('journal.contract');

        foreach ($volumes as $volume) {
            $estimateItemId = $volume['estimate_item_id'] ?? null;
            $estimateItem = $estimateItemId
                ? EstimateItem::query()
                    ->with(['estimate', 'contractLinks.contract.contractor'])
                    ->find($estimateItemId)
                : null;

            if (($volume['auto_attach_contract_coverage'] ?? false) && $estimateItem) {
                $this->journalContractCoverageService->ensureCoverage($entry->journal, $estimateItem);
            }

            $entry->workVolumes()->create([
                'estimate_item_id' => $estimateItemId,
                'work_type_id' => $this->resolveWorkVolumeTypeId($entry, $volume, $estimateItem),
                'quantity' => $volume['quantity'],
                'measurement_unit_id' => $this->resolveWorkVolumeMeasurementUnitId($entry, $volume, $estimateItem),
                'notes' => $volume['notes'] ?? null,
            ]);
        }
    }

    protected function syncWorkVolumes(ConstructionJournalEntry $entry, array $volumes): void
    {
        $entry->loadMissing('journal.contract');

        $existing = $entry->workVolumes()->get()->keyBy('id');
        $keptIds = [];

        foreach ($volumes as $volume) {
            $estimateItemId = $volume['estimate_item_id'] ?? null;
            $estimateItem = $estimateItemId
                ? EstimateItem::query()
                    ->with(['estimate', 'contractLinks.contract.contractor'])
                    ->find($estimateItemId)
                : null;

            if (($volume['auto_attach_contract_coverage'] ?? false) && $estimateItem) {
                $this->journalContractCoverageService->ensureCoverage($entry->journal, $estimateItem);
            }

            $payload = [
                'estimate_item_id' => $estimateItemId,
                'work_type_id' => $this->resolveWorkVolumeTypeId($entry, $volume, $estimateItem),
                'quantity' => $volume['quantity'],
                'measurement_unit_id' => $this->resolveWorkVolumeMeasurementUnitId($entry, $volume, $estimateItem),
                'notes' => $volume['notes'] ?? null,
            ];

            $volumeId = isset($volume['id']) ? (int) $volume['id'] : null;
            $model = $volumeId ? $existing->get($volumeId) : null;

            if ($model) {
                $model->update($payload);
            } else {
                $model = $entry->workVolumes()->create($payload);
            }

            $keptIds[] = (int) $model->id;
        }

        $entry->workVolumes()
            ->whereNotIn('id', $keptIds)
            ->delete();

        $entry->unsetRelation('workVolumes');
    }

    private function resolveWorkVolumeTypeId(
        ConstructionJournalEntry $entry,
        array $volume,
        ?EstimateItem $estimateItem
    ): ?int {
        if (!empty($volume['work_type_id'])) {
            return (int) $volume['work_type_id'];
        }

        $entry->loadMissing('scheduleTask.estimateItem');

        return $estimateItem?->work_type_id
            ?? $entry->scheduleTask?->work_type_id
            ?? $entry->scheduleTask?->estimateItem?->work_type_id;
    }

    private function resolveWorkVolumeMeasurementUnitId(
        ConstructionJournalEntry $entry,
        array $volume,
        ?EstimateItem $estimateItem
    ): ?int {
        if (!empty($volume['measurement_unit_id'])) {
            return (int) $volume['measurement_unit_id'];
        }

        $entry->loadMissing('scheduleTask.estimateItem');

        return $estimateItem?->measurement_unit_id
            ?? $entry->scheduleTask?->measurement_unit_id
            ?? $entry->scheduleTask?->estimateItem?->measurement_unit_id;
    }

    protected function attachWorkers(ConstructionJournalEntry $entry, array $workers): void
    {
        foreach ($workers as $worker) {
            $entry->workers()->create([
                'estimate_item_id' => $worker['estimate_item_id'] ?? null,
                'specialty' => $worker['specialty'],
                'workers_count' => $worker['workers_count'],
                'hours_worked' => $worker['hours_worked'] ?? null,
            ]);
        }
    }

    protected function attachEquipment(ConstructionJournalEntry $entry, array $equipment): void
    {
        foreach ($equipment as $item) {
            $entry->equipment()->create([
                'estimate_item_id' => $item['estimate_item_id'] ?? null,
                'equipment_name' => $item['equipment_name'],
                'equipment_type' => $item['equipment_type'] ?? null,
                'quantity' => $item['quantity'] ?? 1,
                'hours_used' => $item['hours_used'] ?? null,
            ]);
        }
    }

    protected function attachMaterials(ConstructionJournalEntry $entry, array $materials): void
    {
        foreach ($materials as $material) {
            $consumption = $this->writeOffJournalMaterialFromCustody($entry, $material);

            $entry->materials()->create([
                'material_id' => $material['material_id'] ?? null,
                'estimate_item_id' => $material['estimate_item_id'] ?? null,
                'project_material_delivery_id' => $material['project_material_delivery_id'] ?? null,
                'warehouse_movement_id' => $consumption['movement']?->id,
                'custody_warehouse_id' => $consumption['custody_warehouse']?->id,
                'material_name' => $material['material_name'],
                'quantity' => $material['quantity'],
                'measurement_unit' => $material['measurement_unit'],
                'notes' => $material['notes'] ?? null,
            ]);
        }
    }

    private function assertJournalMaterialConsumptionCanBeReplaced(ConstructionJournalEntry $entry): void
    {
        if ($entry->materials()->whereNotNull('warehouse_movement_id')->exists()) {
            throw new DomainException(trans_message('basic_warehouse.validation.journal_consumption_update_not_supported'));
        }
    }

    private function writeOffJournalMaterialFromCustody(ConstructionJournalEntry $entry, array $material): array
    {
        $deliveryId = $material['project_material_delivery_id'] ?? null;

        if (!$deliveryId) {
            return [
                'movement' => null,
                'custody_warehouse' => null,
            ];
        }

        $journal = $entry->journal()->firstOrFail();
        $delivery = $this->resolveAcceptedProjectMaterialDelivery($journal, $material);
        $responsibleUserId = (int) $entry->created_by_user_id;
        $custodyWarehouse = $this->resolveResponsibleCustodyWarehouse($journal, $responsibleUserId);

        if (!$custodyWarehouse) {
            throw new DomainException(trans_message('basic_warehouse.validation.insufficient_custody_stock', [
                'available' => 0,
                'requested' => (float) ($material['quantity'] ?? 0),
            ]));
        }

        $result = $this->warehouseService->writeOffAsset(
            (int) $journal->organization_id,
            (int) $custodyWarehouse->id,
            (int) $delivery->material_id,
            (float) $material['quantity'],
            [
                'project_id' => (int) $journal->project_id,
                'user_id' => $responsibleUserId,
                'related_user_id' => $responsibleUserId,
                'operation_category' => WarehouseMovement::CATEGORY_PRODUCTION_USAGE,
                'project_material_delivery_id' => (int) $delivery->id,
                'construction_journal_entry_id' => (int) $entry->id,
                'reason' => trans_message('basic_warehouse.messages.production_usage_reason'),
            ]
        );

        return [
            'movement' => $result['movement'],
            'custody_warehouse' => $custodyWarehouse,
        ];
    }

    protected function assertProjectMaterialDeliveryScope(
        ConstructionJournal $journal,
        array $material,
        ?ConstructionJournalEntry $entry = null,
        ?User $user = null
    ): void {
        $deliveryId = $material['project_material_delivery_id'] ?? null;

        if (!$deliveryId) {
            return;
        }

        $delivery = $this->resolveAcceptedProjectMaterialDelivery($journal, $material);

        if (
            isset($material['material_id'])
            && (int) $material['material_id'] > 0
            && (int) $material['material_id'] !== (int) $delivery->material_id
        ) {
            throw new DomainException(trans_message('construction_journal.errors.invalid_project_material_delivery'));
        }

        $quantity = (float) ($material['quantity'] ?? 0);

        $usedQuantity = (float) $delivery->journalMaterials()
            ->when($entry, fn ($query) => $query->where('journal_entry_id', '!=', $entry->id))
            ->sum('quantity');
        $availableQuantity = max(0.0, (float) $delivery->accepted_quantity - $usedQuantity);

        if ($quantity > $availableQuantity) {
            throw new DomainException(trans_message('construction_journal.errors.project_material_delivery_quantity_exceeded'));
        }

        $responsibleUserId = (int) ($entry?->created_by_user_id ?? $user?->id ?? $journal->created_by_user_id);
        $custodyWarehouse = $this->resolveResponsibleCustodyWarehouse($journal, $responsibleUserId);
        $custodyAvailableQuantity = $custodyWarehouse
            ? $this->availableWarehouseQuantity((int) $journal->organization_id, (int) $custodyWarehouse->id, (int) $delivery->material_id)
            : 0.0;

        if ($quantity > $custodyAvailableQuantity) {
            throw new DomainException(trans_message('basic_warehouse.validation.insufficient_custody_stock', [
                'available' => $custodyAvailableQuantity,
                'requested' => $quantity,
            ]));
        }
    }

    private function resolveAcceptedProjectMaterialDelivery(ConstructionJournal $journal, array $material): ProjectMaterialDelivery
    {
        $delivery = ProjectMaterialDelivery::query()
            ->where('id', $material['project_material_delivery_id'])
            ->where('organization_id', $journal->organization_id)
            ->where('project_id', $journal->project_id)
            ->where('status', ProjectMaterialDeliveryStatusEnum::ACCEPTED->value)
            ->first();

        if (!$delivery) {
            throw new DomainException(trans_message('construction_journal.errors.invalid_project_material_delivery'));
        }

        return $delivery;
    }

    private function resolveResponsibleCustodyWarehouse(
        ConstructionJournal $journal,
        int $responsibleUserId
    ): ?OrganizationWarehouse {
        if ($responsibleUserId <= 0) {
            return null;
        }

        return OrganizationWarehouse::query()
            ->where('organization_id', $journal->organization_id)
            ->where('project_id', $journal->project_id)
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

    protected function generateJournalNumber(Project $project): string
    {
        $year = now()->year;
        $count = ConstructionJournal::where('project_id', $project->id)
            ->whereYear('created_at', $year)
            ->count() + 1;

        return "ОЖР-{$project->id}-{$year}-{$count}";
    }

    protected function assertContractScope(Project $project, ?int $contractId): void
    {
        if (!$contractId) {
            return;
        }

        $contract = Contract::query()
            ->where('id', $contractId)
            ->where('organization_id', $project->organization_id)
            ->where(function ($query) use ($project): void {
                $query->where('project_id', $project->id)
                    ->orWhereHas('projects', function ($projectsQuery) use ($project): void {
                        $projectsQuery->where('projects.id', $project->id);
                    });
            })
            ->first();

        if (!$contract) {
            throw new DomainException(trans_message('construction_journal.errors.invalid_contract'));
        }
    }

    protected function assertEntryScope(
        ConstructionJournal $journal,
        array $data,
        ?ConstructionJournalEntry $entry = null,
        ?User $user = null
    ): void
    {
        $estimateId = $data['estimate_id'] ?? $entry?->estimate_id;
        $scheduleTaskId = $data['schedule_task_id'] ?? $entry?->schedule_task_id;

        $this->assertEstimateScope($journal, $estimateId);
        $this->assertScheduleTaskScope($journal, $scheduleTaskId, $estimateId);

        foreach (($data['work_volumes'] ?? []) as $volume) {
            $this->assertEstimateItemScope($journal, $volume['estimate_item_id'] ?? null, $estimateId);
            $this->assertWorkTypeScope($journal, $volume['work_type_id'] ?? null);
            $this->assertMeasurementUnitScope($journal, $volume['measurement_unit_id'] ?? null);
        }

        foreach (($data['materials'] ?? []) as $material) {
            $this->assertMaterialScope($journal, $material['material_id'] ?? null);
            $this->assertEstimateResourceItemScope($journal, $material['estimate_item_id'] ?? null, $estimateId, ['material']);
            $this->assertProjectMaterialDeliveryScope($journal, $material, $entry, $user);
        }

        foreach (($data['equipment'] ?? []) as $equipment) {
            $this->assertEstimateResourceItemScope(
                $journal,
                $equipment['estimate_item_id'] ?? null,
                $estimateId,
                ['equipment', 'machinery'],
            );
        }

        foreach (($data['workers'] ?? []) as $worker) {
            $this->assertEstimateResourceItemScope($journal, $worker['estimate_item_id'] ?? null, $estimateId, ['labor']);
        }
    }

    protected function assertEstimateScope(ConstructionJournal $journal, ?int $estimateId): void
    {
        if (!$estimateId) {
            return;
        }

        $estimate = Estimate::query()
            ->where('id', $estimateId)
            ->where('organization_id', $journal->organization_id)
            ->where('project_id', $journal->project_id)
            ->first();

        if (!$estimate) {
            throw new DomainException(trans_message('construction_journal.errors.invalid_estimate'));
        }
    }

    protected function assertScheduleTaskScope(ConstructionJournal $journal, ?int $scheduleTaskId, ?int $estimateId): void
    {
        if (!$scheduleTaskId) {
            return;
        }

        $task = ScheduleTask::query()
            ->where('id', $scheduleTaskId)
            ->where('organization_id', $journal->organization_id)
            ->whereHas('schedule', function ($query) use ($journal): void {
                $query->where('project_id', $journal->project_id);
            })
            ->first();

        if (!$task) {
            throw new DomainException(trans_message('construction_journal.errors.invalid_schedule_task'));
        }

        if ($estimateId && $task->estimate_item_id) {
            $estimateItem = EstimateItem::query()
                ->where('id', $task->estimate_item_id)
                ->whereHas('estimate', function ($query) use ($estimateId): void {
                    $query->where('id', $estimateId);
                })
                ->first();

            if (!$estimateItem) {
                throw new DomainException(trans_message('construction_journal.errors.schedule_task_estimate_mismatch'));
            }
        }
    }

    protected function assertEstimateItemScope(ConstructionJournal $journal, ?int $estimateItemId, ?int $estimateId): void
    {
        if (!$estimateItemId) {
            return;
        }

        $item = EstimateItem::query()
            ->where('id', $estimateItemId)
            ->whereHas('estimate', function ($query) use ($journal, $estimateId): void {
                $query->where('organization_id', $journal->organization_id)
                    ->where('project_id', $journal->project_id);

                if ($estimateId) {
                    $query->where('id', $estimateId);
                }
            })
            ->first();

        if (!$item) {
            throw new DomainException(trans_message('construction_journal.errors.invalid_estimate_item'));
        }
    }

    protected function assertMaterialScope(ConstructionJournal $journal, ?int $materialId): void
    {
        if (!$materialId) {
            return;
        }

        $material = Material::query()
            ->where('id', $materialId)
            ->where('organization_id', $journal->organization_id)
            ->first();

        if (!$material) {
            throw new DomainException(trans_message('construction_journal.errors.invalid_material'));
        }
    }

    protected function assertEstimateResourceItemScope(
        ConstructionJournal $journal,
        ?int $estimateItemId,
        ?int $estimateId,
        array $allowedTypes
    ): void {
        if (!$estimateItemId) {
            return;
        }

        $item = EstimateItem::query()
            ->where('id', $estimateItemId)
            ->whereIn('item_type', $allowedTypes)
            ->whereHas('estimate', function ($query) use ($journal, $estimateId): void {
                $query->where('organization_id', $journal->organization_id)
                    ->where('project_id', $journal->project_id);

                if ($estimateId) {
                    $query->where('id', $estimateId);
                }
            })
            ->first();

        if (!$item) {
            throw new DomainException(trans_message('construction_journal.errors.invalid_estimate_item'));
        }
    }

    protected function assertWorkTypeScope(ConstructionJournal $journal, ?int $workTypeId): void
    {
        if (!$workTypeId) {
            return;
        }

        $workType = WorkType::query()
            ->where('id', $workTypeId)
            ->where(function ($query) use ($journal): void {
                $query->where('organization_id', $journal->organization_id)
                    ->orWhereNull('organization_id');
            })
            ->first();

        if (!$workType) {
            throw new DomainException(trans_message('construction_journal.errors.invalid_work_type'));
        }
    }

    protected function assertMeasurementUnitScope(ConstructionJournal $journal, ?int $measurementUnitId): void
    {
        if (!$measurementUnitId) {
            return;
        }

        $unit = MeasurementUnit::query()
            ->where('id', $measurementUnitId)
            ->where(function ($query) use ($journal): void {
                $query->where('organization_id', $journal->organization_id)
                    ->orWhereNull('organization_id')
                    ->orWhere('is_system', true);
            })
            ->first();

        if (!$unit) {
            throw new DomainException(trans_message('construction_journal.errors.invalid_measurement_unit'));
        }
    }

    private function recordJournalAudit(string $event, ConstructionJournal $journal, ?User $user = null): void
    {
        $this->logging->audit($event, [
            'organization_id' => $journal->organization_id,
            'project_id' => $journal->project_id,
            'journal_id' => $journal->id,
            'journal_name' => $journal->name,
            'journal_number' => $journal->journal_number,
            'contract_id' => $journal->contract_id,
            'status' => $this->enumValue($journal->status),
            'performed_by' => $user?->id ?? Auth::id(),
        ]);
    }

    private function recordEntryAudit(string $event, ConstructionJournalEntry $entry, ?User $user = null): void
    {
        $entry->loadMissing('journal');

        $this->logging->audit($event, [
            'organization_id' => $entry->journal?->organization_id,
            'project_id' => $entry->journal?->project_id,
            'journal_id' => $entry->journal_id,
            'journal_name' => $entry->journal?->name,
            'journal_entry_id' => $entry->id,
            'entry_number' => $entry->entry_number,
            'entry_date' => $this->dateValue($entry->entry_date),
            'status' => $this->enumValue($entry->status),
            'performed_by' => $user?->id ?? Auth::id(),
        ]);
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return $value !== null ? (string) $value : null;
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toDateString();
        }

        return is_string($value) ? $value : null;
    }
}
