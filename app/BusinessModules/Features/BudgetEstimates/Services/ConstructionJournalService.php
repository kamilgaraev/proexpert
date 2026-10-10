<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\BusinessModules\Features\BasicWarehouse\Enums\ProjectMaterialDeliveryStatusEnum;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService;
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
use BackedEnum;
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
    ) {}

    public function createJournal(Project $project, array $data, User $user): ConstructionJournal
    {
        $access = app(\App\Services\ConstructionJournal\ConstructionJournalAccessService::class);
        if (! $access->canAccessProject($user, $project) || ! $access->hasPermission($user, $project, ['create', '*'])) {
            throw new \Illuminate\Auth\Access\AuthorizationException(trans_message('construction_journal.errors.access_denied'));
        }
        return DB::transaction(function () use ($project, $data, $user): ConstructionJournal {
            if (! isset($data['contract_id'])) {
                throw new DomainException(trans_message('construction_journal.errors.contract_required'));
            }

            $this->assertContractScope($project, $data['contract_id']);

            $journal = ConstructionJournal::create([
                'organization_id' => $project->organization_id,
                'performing_organization_id' => $user->current_organization_id,
                'project_id' => $project->id,
                'contract_id' => $data['contract_id'],
                'name' => $data['name'],
                'journal_number' => $data['journal_number'] ?? $this->generateJournalNumber($project),
                'start_date' => $data['start_date'] ?? now(),
                'end_date' => $data['end_date'] ?? null,
                'status' => JournalStatusEnum::ACTIVE,
                'created_by_user_id' => $user->id,
            ]);

            $journal->load(['project', 'contract', 'createdBy']);
            $this->recordJournalAudit('construction_journal.created', $journal, $user);

            return $journal;
        });
    }

    public function updateJournal(ConstructionJournal $journal, array $data, ?User $actor = null): ConstructionJournal
    {
        $this->assertJournalWrite($journal, ['edit', '*'], $actor);
        $data = array_intersect_key($data, array_flip(['name', 'journal_number', 'contract_id', 'start_date', 'end_date']));
        if (array_key_exists('contract_id', $data)) {
            $this->assertContractScope($journal->project, $data['contract_id']);
        }

        $journal->update($data);

        $journal = $journal->fresh(['project', 'contract', 'createdBy']);
        $this->recordJournalAudit('construction_journal.updated', $journal);

        return $journal;
    }

    public function deleteJournal(ConstructionJournal $journal, ?User $actor = null): bool
    {
        $this->assertJournalWrite($journal, ['delete', '*'], $actor);
        return DB::transaction(function () use ($journal): bool {
            $journal = ConstructionJournal::query()->whereKey($journal->id)->lockForUpdate()->firstOrFail();
            if ($journal->status !== JournalStatusEnum::ACTIVE || $journal->entries()->withTrashed()->exists()) {
                throw new DomainException(trans_message('construction_journal.errors.delete_nonempty_forbidden'));
            }

            $deleted = (bool) $journal->delete();
            $this->recordJournalAudit('construction_journal.deleted', $journal);

            return $deleted;
        });
    }

    public function closeJournal(ConstructionJournal $journal, ?User $actor = null): ConstructionJournal
    {
        $this->assertJournalWrite($journal, ['edit', '*'], $actor);
        return DB::transaction(function () use ($journal): ConstructionJournal {
            $journal = ConstructionJournal::query()->whereKey($journal->id)->lockForUpdate()->firstOrFail();
            if ($journal->status !== JournalStatusEnum::ACTIVE || $journal->entries()->whereIn('status', [
                JournalEntryStatusEnum::DRAFT,
                JournalEntryStatusEnum::SUBMITTED,
                JournalEntryStatusEnum::REJECTED,
            ])->exists()) {
                throw new DomainException(trans_message('construction_journal.errors.close_pending_entries'));
            }
            $journal->update(['status' => JournalStatusEnum::CLOSED, 'end_date' => $journal->end_date ?? now()]);
            $this->recordJournalAudit('construction_journal.closed', $journal);

            return $journal->fresh(['project', 'contract', 'createdBy']);
        });
    }

    public function archiveJournal(ConstructionJournal $journal, ?User $actor = null): ConstructionJournal
    {
        $this->assertJournalWrite($journal, ['edit', '*'], $actor);
        return DB::transaction(function () use ($journal): ConstructionJournal {
            $journal = ConstructionJournal::query()->whereKey($journal->id)->lockForUpdate()->firstOrFail();
            if ($journal->status !== JournalStatusEnum::CLOSED) {
                throw new DomainException(trans_message('construction_journal.errors.archive_invalid_status'));
            }
            $journal->update(['status' => JournalStatusEnum::ARCHIVED]);
            $this->recordJournalAudit('construction_journal.archived', $journal);

            return $journal->fresh(['project', 'contract', 'createdBy']);
        });
    }

    public function reopenJournal(ConstructionJournal $journal, ?User $actor = null): ConstructionJournal
    {
        $this->assertJournalWrite($journal, ['reopen', '*'], $actor);
        return DB::transaction(function () use ($journal): ConstructionJournal {
            $journal = ConstructionJournal::query()->whereKey($journal->id)->lockForUpdate()->firstOrFail();
            if ($journal->status !== JournalStatusEnum::CLOSED) {
                throw new DomainException(trans_message('construction_journal.errors.reopen_invalid_status'));
            }
            $journal->update(['status' => JournalStatusEnum::ACTIVE, 'end_date' => null]);
            $this->recordJournalAudit('construction_journal.reopened', $journal);

            return $journal->fresh(['project', 'contract', 'createdBy']);
        });
    }

    public function createEntry(ConstructionJournal $journal, array $data, User $user): ConstructionJournalEntry
    {
        $this->assertJournalWrite($journal, ['create', '*'], $user);
        return DB::transaction(function () use ($journal, $data, $user): ConstructionJournalEntry {
            $journal = ConstructionJournal::query()->whereKey($journal->id)->lockForUpdate()->firstOrFail();
            $this->assertJournalActive($journal);
            $this->assertEntryScope($journal, $data, null, $user);

            $entryNumber = $data['entry_number'] ?? $journal->getNextEntryNumber();

            $entry = ConstructionJournalEntry::create([
                'journal_id' => $journal->id,
                'schedule_task_id' => $data['schedule_task_id'] ?? null,
                'estimate_id' => $data['estimate_id'] ?? null,
                'entry_date' => $data['entry_date'],
                'entry_number' => $entryNumber,
                'work_description' => $data['work_description'],
                'status' => JournalEntryStatusEnum::DRAFT,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'payload_fingerprint' => $data['payload_fingerprint'] ?? null,
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

    public function updateEntry(ConstructionJournalEntry $entry, array $data, ?User $actor = null): ConstructionJournalEntry
    {
        $actor ??= Auth::user();
        if (! $actor || ! app(\App\Policies\ConstructionJournalEntryPolicy::class)->update($actor, $entry)) {
            throw new \Illuminate\Auth\Access\AuthorizationException(trans_message('construction_journal.errors.access_denied'));
        }
        return DB::transaction(function () use ($entry, $data): ConstructionJournalEntry {
            $entry = $this->lockJournalAndEntry($entry);
            $this->assertEntryEditable($entry);
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
                $this->attachWorkers($entry, $data['workers'] ?? [], true);
            }

            if (array_key_exists('equipment', $data)) {
                $this->attachEquipment($entry, $data['equipment'] ?? [], true);
            }

            if (array_key_exists('materials', $data)) {
                $this->attachMaterials($entry, $data['materials'] ?? [], true);
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

    public function deleteEntry(ConstructionJournalEntry $entry, ?User $actor = null): bool
    {
        $actor ??= Auth::user();
        if (! $actor || ! app(\App\Policies\ConstructionJournalEntryPolicy::class)->delete($actor, $entry)) {
            throw new \Illuminate\Auth\Access\AuthorizationException(trans_message('construction_journal.errors.access_denied'));
        }
        return DB::transaction(function () use ($entry): bool {
            $entry = $this->lockJournalAndEntry($entry);
            if ($entry->status !== JournalEntryStatusEnum::DRAFT || $entry->approvalEvents()->exists()) {
                throw new DomainException(trans_message('construction_journal.errors.entry_delete_history_forbidden'));
            }
            $this->completedWorkFactService->deleteJournalEntryFacts($entry);
            $deleted = (bool) $entry->delete();
            $this->recordEntryAudit('construction_journal_entry.deleted', $entry);

            return $deleted;
        });
    }

    private function lockJournalAndEntry(ConstructionJournalEntry $entry): ConstructionJournalEntry
    {
        $journal = ConstructionJournal::query()
            ->whereKey($entry->journal_id)
            ->lockForUpdate()
            ->firstOrFail();
        $this->assertJournalActive($journal);

        return ConstructionJournalEntry::query()
            ->with('journal')
            ->whereKey($entry->id)
            ->where('journal_id', $journal->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertJournalActive(ConstructionJournal $journal): void
    {
        if ($journal->status !== JournalStatusEnum::ACTIVE) {
            throw new DomainException(trans_message('construction_journal.errors.journal_not_active'));
        }
    }

    private function assertEntryEditable(ConstructionJournalEntry $entry): void
    {
        if (! $entry->canBeEdited()) {
            throw new DomainException(trans_message('construction_journal.errors.entry_edit_invalid_status'));
        }
    }

    public function getDailyEntries(ConstructionJournal $journal, Carbon $date, ?User $actor = null): Collection
    {
        $this->assertJournalRead($journal, $actor);
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

    public function getEntriesForPeriod(ConstructionJournal $journal, Carbon $from, Carbon $to, ?User $actor = null): Collection
    {
        $this->assertJournalRead($journal, $actor);
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

            $entry->workVolumes()->create([
                'estimate_item_id' => $estimateItemId,
                'work_name' => $estimateItemId ? null : trim((string) ($volume['work_name'] ?? '')),
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

            $payload = [
                'estimate_item_id' => $estimateItemId,
                'work_name' => $estimateItemId ? null : trim((string) ($volume['work_name'] ?? '')),
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
        if (! $estimateItem) {
            return ! empty($volume['work_type_id']) ? (int) $volume['work_type_id'] : null;
        }
        if ($estimateItem?->work_type_id) {
            return (int) $estimateItem->work_type_id;
        }

        if (! empty($volume['work_type_id'])) {
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
        if ($estimateItem?->measurement_unit_id) {
            return (int) $estimateItem->measurement_unit_id;
        }

        if (! empty($volume['measurement_unit_id'])) {
            return (int) $volume['measurement_unit_id'];
        }

        $entry->loadMissing('scheduleTask.estimateItem');

        return $estimateItem?->measurement_unit_id
            ?? $entry->scheduleTask?->measurement_unit_id
            ?? $entry->scheduleTask?->estimateItem?->measurement_unit_id;
    }

    protected function attachWorkers(ConstructionJournalEntry $entry, array $workers, bool $replace = false): void
    {
        $rows = [];
        foreach ($workers as $worker) {
            $rows[] = ['id' => $worker['id'] ?? null, 'payload' => [
                'estimate_item_id' => $worker['estimate_item_id'] ?? null,
                'specialty' => $worker['specialty'],
                'workers_count' => $worker['workers_count'],
                'hours_worked' => $worker['hours_worked'] ?? null,
            ]];
        }
        $this->persistResourceRows($entry, 'workers', $rows, $replace);
    }

    protected function attachEquipment(ConstructionJournalEntry $entry, array $equipment, bool $replace = false): void
    {
        $rows = [];
        foreach ($equipment as $item) {
            $rows[] = ['id' => $item['id'] ?? null, 'payload' => [
                'estimate_item_id' => $item['estimate_item_id'] ?? null,
                'equipment_name' => $item['equipment_name'],
                'equipment_type' => $item['equipment_type'] ?? null,
                'quantity' => $item['quantity'] ?? 1,
                'hours_used' => $item['hours_used'] ?? null,
            ]];
        }
        $this->persistResourceRows($entry, 'equipment', $rows, $replace);
    }

    protected function attachMaterials(ConstructionJournalEntry $entry, array $materials, bool $replace = false): void
    {
        $rows = [];
        foreach ($materials as $material) {
            $rows[] = ['id' => $material['id'] ?? null, 'payload' => [
                'material_id' => $material['material_id'] ?? null,
                'estimate_item_id' => $material['estimate_item_id'] ?? null,
                'project_material_delivery_id' => $material['project_material_delivery_id'] ?? null,
                'warehouse_movement_id' => null,
                'custody_warehouse_id' => null,
                'material_name' => $material['material_name'],
                'quantity' => $material['quantity'],
                'measurement_unit' => $material['measurement_unit'],
                'notes' => $material['notes'] ?? null,
            ]];
        }
        $this->persistResourceRows($entry, 'materials', $rows, $replace);
    }

    private function persistResourceRows(ConstructionJournalEntry $entry, string $relation, array $rows, bool $replace): void
    {
        $existing = $replace ? $entry->{$relation}()->get()->keyBy('id') : collect();
        $keptIds = [];
        foreach ($rows as $row) {
            $model = isset($row['id']) ? $existing->get((int) $row['id']) : null;
            if ($model) {
                $model->update($row['payload']);
            } else {
                $model = $entry->{$relation}()->create($row['payload']);
            }
            $keptIds[] = (int) $model->id;
        }
        if ($replace) {
            $entry->{$relation}()->whereNotIn('id', $keptIds)->delete();
            $entry->unsetRelation($relation);
        }
    }

    public function commitMaterialConsumption(ConstructionJournalEntry $entry): void
    {
        $entry->loadMissing('journal');
        $materials = $entry->materials()
            ->whereNull('warehouse_movement_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($materials as $journalMaterial) {
            $material = [
                'material_id' => $journalMaterial->material_id,
                'estimate_item_id' => $journalMaterial->estimate_item_id,
                'project_material_delivery_id' => $journalMaterial->project_material_delivery_id,
                'quantity' => $journalMaterial->quantity,
            ];
            $this->assertProjectMaterialDeliveryScope($entry->journal, $material, $entry);
            $consumption = $this->writeOffJournalMaterialFromCustody($entry, $material);
            $journalMaterial->update([
                'warehouse_movement_id' => $consumption['movement']?->id,
                'custody_warehouse_id' => $consumption['custody_warehouse']?->id,
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

        if (! $deliveryId) {
            return [
                'movement' => null,
                'custody_warehouse' => null,
            ];
        }

        $journal = $entry->journal()->firstOrFail();
        $delivery = $this->resolveAcceptedProjectMaterialDelivery($journal, $material);
        $responsibleUserId = (int) $entry->created_by_user_id;
        $custodyWarehouse = $this->resolveResponsibleCustodyWarehouse($journal, $responsibleUserId);

        if (! $custodyWarehouse) {
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

        if (! $deliveryId) {
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
            ->whereHas('journalEntry', static function ($query): void {
                $query->whereIn('status', [
                    JournalEntryStatusEnum::SUBMITTED,
                    JournalEntryStatusEnum::APPROVED,
                ]);
            })
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

        if (! $delivery) {
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
        if (! $contractId) {
            throw new DomainException(trans_message('construction_journal.errors.contract_required'));
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

        if (! $contract) {
            throw new DomainException(trans_message('construction_journal.errors.invalid_contract'));
        }
    }

    protected function assertEntryScope(
        ConstructionJournal $journal,
        array $data,
        ?ConstructionJournalEntry $entry = null,
        ?User $user = null
    ): void {
        $estimateId = $data['estimate_id'] ?? $entry?->estimate_id;
        $scheduleTaskId = $data['schedule_task_id'] ?? $entry?->schedule_task_id;

        $this->assertEstimateScope($journal, $estimateId);
        $this->assertScheduleTaskScope($journal, $scheduleTaskId, $estimateId);
        $this->assertEntryEstimateConsistency($data['work_volumes'] ?? [], $estimateId);
        $this->assertScheduleTaskVolumeCompatibility($data['work_volumes'] ?? [], $scheduleTaskId);
        foreach (['work_volumes' => 'workVolumes', 'workers' => 'workers', 'equipment' => 'equipment', 'materials' => 'materials'] as $key => $relation) {
            $seenIds = [];
            foreach ($data[$key] ?? [] as $row) {
                if (! isset($row['id'])) {
                    continue;
                }
                $id = (int) $row['id'];
                if ($id <= 0 || in_array($id, $seenIds, true) || ! $entry || ! $entry->{$relation}()->whereKey($id)->exists()) {
                    throw new DomainException(trans_message('construction_journal.errors.access_denied'));
                }
                $seenIds[] = $id;
            }
        }

        foreach (($data['work_volumes'] ?? []) as $volume) {
            if ((float) ($volume['quantity'] ?? 0) <= 0) {
                throw new DomainException(trans_message('construction_journal.errors.validation_work_volumes'));
            }
            if (! ($volume['estimate_item_id'] ?? null)
                && (trim((string) ($volume['work_name'] ?? '')) === ''
                    || ! ($volume['measurement_unit_id'] ?? null)
                    || (float) ($volume['quantity'] ?? 0) <= 0)) {
                throw new DomainException(trans_message('construction_journal.errors.manual_work_required'));
            }
            $this->assertEstimateItemScope($journal, $volume['estimate_item_id'] ?? null, $estimateId);
            $this->assertWorkTypeScope($journal, $volume['work_type_id'] ?? null);
            $this->assertMeasurementUnitScope($journal, $volume['measurement_unit_id'] ?? null);
            $item = ! empty($volume['estimate_item_id']) ? EstimateItem::query()->find($volume['estimate_item_id']) : null;
            if ($item?->work_type_id) {
                $this->assertWorkTypeScope($journal, (int) $item->work_type_id);
            }
            $resolvedUnitId = $item?->measurement_unit_id ?? ($volume['measurement_unit_id'] ?? null);
            if (! $resolvedUnitId && $scheduleTaskId) {
                $task = ScheduleTask::query()->with('estimateItem')->find($scheduleTaskId);
                $resolvedUnitId = $task?->measurement_unit_id ?? $task?->estimateItem?->measurement_unit_id;
            }
            if (! $resolvedUnitId) {
                throw new DomainException(trans_message('construction_journal.errors.invalid_measurement_unit'));
            }
            $this->assertMeasurementUnitScope($journal, (int) $resolvedUnitId);
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
        if (! $estimateId) {
            return;
        }

        $estimate = Estimate::query()
            ->where('id', $estimateId)
            ->where('organization_id', $journal->organization_id)
            ->where('project_id', $journal->project_id)
            ->where('status', 'approved')
            ->first();

        if (! $estimate) {
            throw new DomainException(trans_message('construction_journal.errors.invalid_estimate'));
        }
    }

    protected function assertScheduleTaskScope(ConstructionJournal $journal, ?int $scheduleTaskId, ?int $estimateId): void
    {
        if (! $scheduleTaskId) {
            return;
        }

        $task = ScheduleTask::query()
            ->where('id', $scheduleTaskId)
            ->where('organization_id', $journal->organization_id)
            ->whereHas('schedule', function ($query) use ($journal): void {
                $query->where('project_id', $journal->project_id);
            })
            ->first();

        if (! $task) {
            throw new DomainException(trans_message('construction_journal.errors.invalid_schedule_task'));
        }

        if ($estimateId && $task->estimate_item_id) {
            $estimateItem = EstimateItem::query()
                ->where('id', $task->estimate_item_id)
                ->whereHas('estimate', function ($query) use ($estimateId): void {
                    $query->where('id', $estimateId);
                })
                ->first();

            if (! $estimateItem) {
                throw new DomainException(trans_message('construction_journal.errors.schedule_task_estimate_mismatch'));
            }
        }
    }

    protected function assertEstimateItemScope(ConstructionJournal $journal, ?int $estimateItemId, ?int $estimateId): void
    {
        if (! $estimateItemId) {
            return;
        }

        $item = EstimateItem::query()
            ->where('id', $estimateItemId)
            ->where('item_type', \App\Enums\EstimatePositionItemType::WORK->value)
            ->whereHas('estimate', function ($query) use ($journal, $estimateId): void {
                $query->where('organization_id', $journal->organization_id)
                    ->where('project_id', $journal->project_id)
                    ->where('status', 'approved');

                if ($estimateId) {
                    $query->where('id', $estimateId);
                }
            })
            ->first();

        if (! $item) {
            throw new DomainException(trans_message('construction_journal.errors.invalid_estimate_item'));
        }
    }

    private function assertEntryEstimateConsistency(array $volumes, ?int $estimateId): void
    {
        $itemIds = collect($volumes)
            ->pluck('estimate_item_id')
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();
        if ($itemIds->isEmpty()) {
            return;
        }

        $estimateIds = EstimateItem::query()
            ->whereIn('id', $itemIds)
            ->pluck('estimate_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique();
        if ($estimateIds->count() !== 1 || ($estimateId && ! $estimateIds->contains($estimateId))) {
            throw new DomainException(trans_message('construction_journal.errors.entry_estimate_mismatch'));
        }
    }

    private function assertScheduleTaskVolumeCompatibility(array $volumes, ?int $scheduleTaskId): void
    {
        if (! $scheduleTaskId) {
            return;
        }

        $taskEstimateItemId = ScheduleTask::query()->whereKey($scheduleTaskId)->value('estimate_item_id');
        if (! $taskEstimateItemId) {
            return;
        }

        foreach ($volumes as $volume) {
            if ((int) ($volume['estimate_item_id'] ?? 0) !== (int) $taskEstimateItemId) {
                throw new DomainException(trans_message('construction_journal.errors.schedule_task_volume_mismatch'));
            }
        }
    }

    protected function assertMaterialScope(ConstructionJournal $journal, ?int $materialId): void
    {
        if (! $materialId) {
            return;
        }

        $material = Material::query()
            ->where('id', $materialId)
            ->where('organization_id', $journal->organization_id)
            ->first();

        if (! $material) {
            throw new DomainException(trans_message('construction_journal.errors.invalid_material'));
        }
    }

    protected function assertEstimateResourceItemScope(
        ConstructionJournal $journal,
        ?int $estimateItemId,
        ?int $estimateId,
        array $allowedTypes
    ): void {
        if (! $estimateItemId) {
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

        if (! $item) {
            throw new DomainException(trans_message('construction_journal.errors.invalid_estimate_item'));
        }
    }

    protected function assertWorkTypeScope(ConstructionJournal $journal, ?int $workTypeId): void
    {
        if (! $workTypeId) {
            return;
        }

        $workType = WorkType::query()
            ->where('id', $workTypeId)
            ->where(function ($query) use ($journal): void {
                $query->where('organization_id', $journal->organization_id)
                    ->orWhereNull('organization_id');
            })
            ->first();

        if (! $workType) {
            throw new DomainException(trans_message('construction_journal.errors.invalid_work_type'));
        }
    }

    protected function assertMeasurementUnitScope(ConstructionJournal $journal, ?int $measurementUnitId): void
    {
        if (! $measurementUnitId) {
            return;
        }

        $unit = MeasurementUnit::query()
            ->where('id', $measurementUnitId)
            ->where(function ($query) use ($journal): void {
                $query->where('organization_id', $journal->organization_id)
                    ->orWhereNull('organization_id');
            })
            ->first();

        if (! $unit) {
            throw new DomainException(trans_message('construction_journal.errors.invalid_measurement_unit'));
        }
    }

    private function assertJournalWrite(ConstructionJournal $journal, array $permissions, ?User $actor = null): void
    {
        $actor ??= Auth::user();
        if (! $actor || ! app(\App\Services\ConstructionJournal\ConstructionJournalAccessService::class)->canWrite($actor, $journal, $permissions)) {
            throw new \Illuminate\Auth\Access\AuthorizationException(trans_message('construction_journal.errors.access_denied'));
        }
    }

    private function assertJournalRead(ConstructionJournal $journal, ?User $actor = null): void
    {
        $actor ??= Auth::user();
        if (! $actor) {
            throw new \Illuminate\Auth\Access\AuthorizationException(trans_message('construction_journal.errors.access_denied'));
        }
        app(\App\Services\ConstructionJournal\ConstructionJournalAccessService::class)->assertReadable($actor, $journal);
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
