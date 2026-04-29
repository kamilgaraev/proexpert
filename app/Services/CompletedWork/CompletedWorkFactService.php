<?php

declare(strict_types=1);

namespace App\Services\CompletedWork;

use App\BusinessModules\Features\BudgetEstimates\Services\JournalContractCoverageService;
use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use App\Models\CompletedWork;
use App\Models\ConstructionJournalEntry;
use App\Models\Contract;
use App\Models\ContractEstimateItem;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\JournalEquipment;
use App\Models\JournalMaterial;
use App\Models\JournalWorker;
use App\Models\JournalWorkVolume;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Services\Schedule\ScheduleTaskCompletedWorkService;
use App\Services\Schedule\ScheduleTaskService;
use App\Services\Workflow\JournalScheduleTaskResolver;
use Illuminate\Support\Facades\DB;

class CompletedWorkFactService
{
    public function __construct(
        private readonly ScheduleTaskCompletedWorkService $scheduleTaskCompletedWorkService,
        private readonly ScheduleTaskService $scheduleTaskService,
        private readonly JournalContractCoverageService $journalContractCoverageService,
        private readonly JournalScheduleTaskResolver $scheduleTaskResolver,
    ) {
    }

    public function syncFromJournalEntry(ConstructionJournalEntry $entry): void
    {
        $entry->load([
            'journal.contract',
            'scheduleTask.estimateItem.contractLinks.contract.contractor',
            'workVolumes.estimateItem.contractLinks.contract.contractor',
            'workVolumes.workType',
            'materials.estimateItem.contractLinks.contract.contractor',
            'materials.material',
            'equipment.estimateItem.contractLinks.contract.contractor',
            'workers.estimateItem.contractLinks.contract.contractor',
        ]);

        DB::transaction(function () use ($entry): void {
            $existingWorks = $entry->completedWorks()->orderBy('id')->get();
            $worksByVolumeId = $existingWorks
                ->whereNotNull('journal_work_volume_id')
                ->keyBy('journal_work_volume_id');
            $worksByMaterialId = $existingWorks
                ->whereNotNull('journal_material_id')
                ->keyBy('journal_material_id');
            $worksByEquipmentId = $existingWorks
                ->whereNotNull('journal_equipment_id')
                ->keyBy('journal_equipment_id');
            $worksByWorkerId = $existingWorks
                ->whereNotNull('journal_worker_id')
                ->keyBy('journal_worker_id');
            $legacyWorks = $existingWorks
                ->whereNull('journal_work_volume_id')
                ->whereNull('journal_material_id')
                ->whereNull('journal_equipment_id')
                ->whereNull('journal_worker_id')
                ->values();
            $workVolumes = $entry->workVolumes->values();
            $materials = $entry->materials
                ->whereNotNull('estimate_item_id')
                ->values();
            $equipment = $entry->equipment
                ->whereNotNull('estimate_item_id')
                ->values();
            $workers = $entry->workers
                ->whereNotNull('estimate_item_id')
                ->values();
            $syncedTaskIds = collect();
            $syncedWorkIds = collect();

            foreach ($workVolumes as $volume) {
                $task = $this->scheduleTaskResolver->resolveForVolume($entry, $volume);
                $payload = $this->buildPayloadFromJournalVolume($entry, $volume, $task);

                /** @var CompletedWork $completedWork */
                $completedWork = $worksByVolumeId->get($volume->id)
                    ?? $legacyWorks->shift()
                    ?? new CompletedWork();
                $completedWork->fill($payload);
                $completedWork->save();
                $syncedWorkIds->push((int) $completedWork->id);

                if ($completedWork->schedule_task_id) {
                    $syncedTaskIds->push((int) $completedWork->schedule_task_id);
                }
            }

            foreach ($materials as $material) {
                $payload = $this->buildPayloadFromJournalMaterial($entry, $material);

                /** @var CompletedWork $completedWork */
                $completedWork = $worksByMaterialId->get($material->id) ?? new CompletedWork();
                $completedWork->fill($payload);
                $completedWork->save();
                $syncedWorkIds->push((int) $completedWork->id);
            }

            foreach ($equipment as $equipmentItem) {
                $payload = $this->buildPayloadFromJournalEquipment($entry, $equipmentItem);

                /** @var CompletedWork $completedWork */
                $completedWork = $worksByEquipmentId->get($equipmentItem->id) ?? new CompletedWork();
                $completedWork->fill($payload);
                $completedWork->save();
                $syncedWorkIds->push((int) $completedWork->id);
            }

            foreach ($workers as $worker) {
                $payload = $this->buildPayloadFromJournalWorker($entry, $worker);

                /** @var CompletedWork $completedWork */
                $completedWork = $worksByWorkerId->get($worker->id) ?? new CompletedWork();
                $completedWork->fill($payload);
                $completedWork->save();
                $syncedWorkIds->push((int) $completedWork->id);
            }

            $this->backfillEntryScheduleTask($entry);

            $existingWorks
                ->reject(fn (CompletedWork $completedWork): bool => $syncedWorkIds->contains((int) $completedWork->id))
                ->each(function (CompletedWork $completedWork) use ($syncedTaskIds): void {
                    if ($completedWork->schedule_task_id) {
                        $syncedTaskIds->push((int) $completedWork->schedule_task_id);
                    }

                    $completedWork->delete();
                });

            $syncedTaskIds
                ->filter()
                ->unique()
                ->each(fn (int $taskId) => $this->syncTaskById($taskId));
        });
    }

    public function deleteJournalEntryFacts(ConstructionJournalEntry $entry): void
    {
        $taskIds = $entry->completedWorks()
            ->whereNotNull('schedule_task_id')
            ->pluck('schedule_task_id')
            ->map(fn ($id) => (int) $id)
            ->unique();

        DB::transaction(function () use ($entry, $taskIds): void {
            $entry->completedWorks()->delete();

            $taskIds->each(fn (int $taskId) => $this->syncTaskById($taskId));
        });
    }

    public function syncJournalEntriesForContractEstimateCoverage(
        Contract $contract,
        Estimate $estimate,
        array $estimateItemIds = [],
    ): int {
        $syncedCount = 0;
        $estimateItemIds = array_values(array_filter(array_map('intval', $estimateItemIds)));

        $volumeEntryIds = JournalWorkVolume::query()
            ->when(
                $estimateItemIds !== [],
                fn ($query) => $query->whereIn('estimate_item_id', $estimateItemIds),
                fn ($query) => $query->whereHas('estimateItem', function ($estimateItemQuery) use ($estimate): void {
                    $estimateItemQuery->where('estimate_id', $estimate->id);
                })
            )
            ->pluck('journal_entry_id')
            ->unique()
            ->values();

        $materialEntryIds = JournalMaterial::query()
            ->when(
                $estimateItemIds !== [],
                fn ($query) => $query->whereIn('estimate_item_id', $estimateItemIds),
                fn ($query) => $query->whereHas('estimateItem', function ($estimateItemQuery) use ($estimate): void {
                    $estimateItemQuery->where('estimate_id', $estimate->id);
                })
            )
            ->pluck('journal_entry_id')
            ->unique()
            ->values();

        $equipmentEntryIds = JournalEquipment::query()
            ->when(
                $estimateItemIds !== [],
                fn ($query) => $query->whereIn('estimate_item_id', $estimateItemIds),
                fn ($query) => $query->whereHas('estimateItem', function ($estimateItemQuery) use ($estimate): void {
                    $estimateItemQuery->where('estimate_id', $estimate->id);
                })
            )
            ->pluck('journal_entry_id')
            ->unique()
            ->values();

        $workerEntryIds = JournalWorker::query()
            ->when(
                $estimateItemIds !== [],
                fn ($query) => $query->whereIn('estimate_item_id', $estimateItemIds),
                fn ($query) => $query->whereHas('estimateItem', function ($estimateItemQuery) use ($estimate): void {
                    $estimateItemQuery->where('estimate_id', $estimate->id);
                })
            )
            ->pluck('journal_entry_id')
            ->unique()
            ->values();

        $entryIds = $volumeEntryIds
            ->merge($materialEntryIds)
            ->merge($equipmentEntryIds)
            ->merge($workerEntryIds)
            ->unique()
            ->values();

        if ($entryIds->isEmpty()) {
            return 0;
        }

        ConstructionJournalEntry::query()
            ->whereIn('id', $entryIds)
            ->with([
                'journal.contract',
                'scheduleTask.estimateItem.contractLinks.contract.contractor',
                'workVolumes.estimateItem.contractLinks.contract.contractor',
                'workVolumes.workType',
                'materials.estimateItem.contractLinks.contract.contractor',
                'materials.material',
                'equipment.estimateItem.contractLinks.contract.contractor',
                'workers.estimateItem.contractLinks.contract.contractor',
            ])
            ->chunkById(100, function ($entries) use ($contract, &$syncedCount): void {
                foreach ($entries as $entry) {
                    if ((int) ($entry->journal?->contract_id ?? 0) !== (int) $contract->id) {
                        continue;
                    }

                    $this->syncFromJournalEntry($entry);
                    $syncedCount++;
                }
            });

        return $syncedCount;
    }

    public function repairJournalScheduleLinks(?int $organizationId = null): int
    {
        $repairedCount = 0;

        $query = ConstructionJournalEntry::query()
            ->whereNull('schedule_task_id')
            ->whereHas('workVolumes', function ($workVolumesQuery): void {
                $workVolumesQuery->whereNotNull('estimate_item_id');
            })
            ->when($organizationId !== null, function ($entryQuery) use ($organizationId): void {
                $entryQuery->whereHas('journal', function ($journalQuery) use ($organizationId): void {
                    $journalQuery->where('organization_id', $organizationId);
                });
            })
            ->with([
                'journal.contract',
                'scheduleTask.estimateItem.contractLinks.contract.contractor',
                'workVolumes.estimateItem.contractLinks.contract.contractor',
                'workVolumes.workType',
                'materials.estimateItem.contractLinks.contract.contractor',
                'materials.material',
                'equipment.estimateItem.contractLinks.contract.contractor',
                'workers.estimateItem.contractLinks.contract.contractor',
            ]);

        $query->chunkById(100, function ($entries) use (&$repairedCount): void {
            foreach ($entries as $entry) {
                if (! $this->scheduleTaskResolver->resolveUniqueTaskForEntry($entry)) {
                    continue;
                }

                $this->syncFromJournalEntry($entry);

                if ($entry->fresh()->schedule_task_id) {
                    $repairedCount++;
                }
            }
        });

        return $repairedCount;
    }

    public function repairJournalResourceFacts(?int $organizationId = null): int
    {
        $syncedCount = 0;

        ConstructionJournalEntry::query()
            ->where(function ($entryQuery): void {
                $entryQuery
                    ->whereHas('materials', fn ($query) => $query->whereNotNull('estimate_item_id'))
                    ->orWhereHas('equipment', fn ($query) => $query->whereNotNull('estimate_item_id'))
                    ->orWhereHas('workers', fn ($query) => $query->whereNotNull('estimate_item_id'));
            })
            ->when($organizationId !== null, function ($entryQuery) use ($organizationId): void {
                $entryQuery->whereHas('journal', function ($journalQuery) use ($organizationId): void {
                    $journalQuery->where('organization_id', $organizationId);
                });
            })
            ->with([
                'journal.contract',
                'scheduleTask.estimateItem.contractLinks.contract.contractor',
                'workVolumes.estimateItem.contractLinks.contract.contractor',
                'workVolumes.workType',
                'materials.estimateItem.contractLinks.contract.contractor',
                'materials.material',
                'equipment.estimateItem.contractLinks.contract.contractor',
                'workers.estimateItem.contractLinks.contract.contractor',
            ])
            ->chunkById(100, function ($entries) use (&$syncedCount): void {
                foreach ($entries as $entry) {
                    $this->syncFromJournalEntry($entry);
                    $syncedCount++;
                }
            });

        return $syncedCount;
    }

    public function attachToTask(CompletedWork $completedWork, ScheduleTask $task): CompletedWork
    {
        $completedWork->forceFill([
            'schedule_task_id' => $task->id,
            'estimate_item_id' => $completedWork->estimate_item_id ?? $task->estimate_item_id,
            'project_id' => $task->schedule->project_id,
            'organization_id' => $task->organization_id,
            'planning_status' => CompletedWork::PLANNING_PLANNED,
        ]);

        if (!$completedWork->work_type_id && $task->work_type_id) {
            $completedWork->work_type_id = $task->work_type_id;
        }

        $completedWork->save();
        $this->syncTaskById($task->id);

        return $completedWork->fresh([
            'scheduleTask.schedule',
            'estimateItem.measurementUnit',
            'journalEntry',
            'workType',
            'user',
            'project',
            'contract.contractor',
            'contractor',
            'materials.measurementUnit',
        ]);
    }

    public function createTaskFromWork(CompletedWork $completedWork, ProjectSchedule $schedule, int $userId): ScheduleTask
    {
        $completedWork->loadMissing(['estimateItem.measurementUnit', 'workType']);

        $quantity = (float) ($completedWork->quantity ?? $completedWork->completed_quantity ?? 0);
        $completedQuantity = (float) ($completedWork->completed_quantity ?? $quantity);
        $progressPercent = $quantity > 0 ? min(100, round(($completedQuantity / $quantity) * 100, 2)) : 0;
        $sortOrder = $this->scheduleTaskService->getNextSortOrder($schedule->id);

        $task = ScheduleTask::create([
            'schedule_id' => $schedule->id,
            'organization_id' => $schedule->organization_id,
            'estimate_item_id' => $completedWork->estimate_item_id,
            'work_type_id' => $completedWork->work_type_id,
            'created_by_user_id' => $userId,
            'name' => $this->resolveTaskName($completedWork),
            'description' => $completedWork->notes,
            'task_type' => 'task',
            'planned_start_date' => $completedWork->completion_date,
            'planned_end_date' => $completedWork->completion_date,
            'quantity' => $quantity > 0 ? $quantity : null,
            'completed_quantity' => $completedQuantity > 0 ? $completedQuantity : null,
            'measurement_unit_id' => $completedWork->estimateItem?->measurement_unit_id,
            'progress_percent' => $progressPercent,
            'status' => $progressPercent >= 100 ? 'completed' : ($progressPercent > 0 ? 'in_progress' : 'not_started'),
            'priority' => 'normal',
            'level' => 0,
            'sort_order' => $sortOrder,
        ]);

        $this->attachToTask($completedWork, $task->load('schedule'));

        return $task->fresh([
            'schedule',
            'assignedUser',
            'workType',
            'measurementUnit',
            'estimateItem',
        ]);
    }

    private function buildPayloadFromJournalVolume(
        ConstructionJournalEntry $entry,
        JournalWorkVolume $volume,
        ?ScheduleTask $task,
    ): array {
        $estimateItem = $volume->estimateItem ?? $task?->estimateItem;
        $contractLink = $this->resolveContractLinkForEntry($entry, $estimateItem);

        $price = null;
        $totalAmount = null;

        if ($contractLink && (float) $contractLink->quantity > 0) {
            $price = round((float) $contractLink->amount / (float) $contractLink->quantity, 2);
            $totalAmount = round($price * (float) $volume->quantity, 2);
        }

        if ($price === null && $estimateItem) {
            $estimatePrice = (float) (
                $estimateItem->actual_unit_price
                ?? $estimateItem->current_unit_price
                ?? $estimateItem->unit_price
                ?? 0
            );

            if ($estimatePrice > 0) {
                $price = round($estimatePrice, 2);
                $totalAmount = round($price * (float) $volume->quantity, 2);
            }
        }

        if ($price === null && $estimateItem) {
            $estimateQuantity = (float) ($estimateItem->quantity_total ?? $estimateItem->quantity ?? 0);
            $estimateAmount = (float) ($estimateItem->current_total_amount ?? $estimateItem->total_amount ?? 0);

            if ($estimateQuantity > 0 && $estimateAmount > 0) {
                $price = round($estimateAmount / $estimateQuantity, 2);
                $totalAmount = round($price * (float) $volume->quantity, 2);
            }
        }

        $contractId = $contractLink?->contract_id;
        $contractorId = $contractLink?->contract?->contractor_id;

        if (!$estimateItem && $entry->journal->contract_id) {
            $contractId = $entry->journal->contract_id;
            $contractorId = $entry->journal->contract?->contractor_id;
        }

        return [
            'organization_id' => $entry->journal->organization_id,
            'project_id' => $entry->journal->project_id,
            'schedule_task_id' => $task?->id,
            'estimate_item_id' => $volume->estimate_item_id ?? $task?->estimate_item_id,
            'journal_entry_id' => $entry->id,
            'journal_work_volume_id' => $volume->id,
            'work_origin_type' => CompletedWork::ORIGIN_JOURNAL,
            'planning_status' => $task ? CompletedWork::PLANNING_PLANNED : CompletedWork::PLANNING_REQUIRES_SCHEDULE,
            'contract_id' => $contractId,
            'work_type_id' => $volume->work_type_id ?? $task?->work_type_id ?? $volume->estimateItem?->work_type_id,
            'user_id' => $entry->created_by_user_id,
            'contractor_id' => $contractorId,
            'quantity' => (float) $volume->quantity,
            'completed_quantity' => (float) $volume->quantity,
            'price' => $price,
            'total_amount' => $totalAmount,
            'completion_date' => $entry->entry_date,
            'notes' => $volume->notes ?: $entry->work_description,
            'status' => $this->mapJournalStatusToCompletedWorkStatus($entry->status),
            'additional_info' => array_filter([
                'journal_entry_number' => $entry->entry_number,
                'journal_status' => $entry->status?->value,
                'weather_conditions' => $entry->weather_conditions,
            ], static fn ($value) => $value !== null && $value !== []),
        ];
    }

    private function buildPayloadFromJournalMaterial(
        ConstructionJournalEntry $entry,
        JournalMaterial $material,
    ): array {
        $quantity = (float) $material->quantity;

        return $this->buildPayloadFromJournalResource(
            entry: $entry,
            estimateItem: $material->estimateItem,
            quantity: $quantity,
            linkField: 'journal_material_id',
            linkId: (int) $material->id,
            notes: $material->notes ?: $material->material_name,
            additionalInfo: [
                'fact_kind' => 'material',
                'material_id' => $material->material_id,
                'material_name' => $material->material_name,
                'measurement_unit' => $material->measurement_unit,
                'journal_material_id' => $material->id,
            ],
        );
    }

    private function buildPayloadFromJournalEquipment(
        ConstructionJournalEntry $entry,
        JournalEquipment $equipment,
    ): array {
        $quantity = (float) ($equipment->quantity ?? 0);
        $hoursUsed = $equipment->hours_used !== null ? (float) $equipment->hours_used : null;
        $factQuantity = $hoursUsed !== null && $hoursUsed > 0
            ? $quantity * $hoursUsed
            : $quantity;

        return $this->buildPayloadFromJournalResource(
            entry: $entry,
            estimateItem: $equipment->estimateItem,
            quantity: $factQuantity,
            linkField: 'journal_equipment_id',
            linkId: (int) $equipment->id,
            notes: $equipment->equipment_name,
            additionalInfo: [
                'fact_kind' => 'equipment',
                'equipment_name' => $equipment->equipment_name,
                'equipment_type' => $equipment->equipment_type,
                'equipment_quantity' => $quantity,
                'hours_used' => $hoursUsed,
                'journal_equipment_id' => $equipment->id,
            ],
        );
    }

    private function buildPayloadFromJournalWorker(
        ConstructionJournalEntry $entry,
        JournalWorker $worker,
    ): array {
        $workersCount = (float) ($worker->workers_count ?? 0);
        $hoursWorked = $worker->hours_worked !== null ? (float) $worker->hours_worked : null;
        $factQuantity = $hoursWorked !== null && $hoursWorked > 0
            ? $workersCount * $hoursWorked
            : $workersCount;

        return $this->buildPayloadFromJournalResource(
            entry: $entry,
            estimateItem: $worker->estimateItem,
            quantity: $factQuantity,
            linkField: 'journal_worker_id',
            linkId: (int) $worker->id,
            notes: $worker->specialty,
            additionalInfo: [
                'fact_kind' => 'labor',
                'specialty' => $worker->specialty,
                'workers_count' => $workersCount,
                'hours_worked' => $hoursWorked,
                'journal_worker_id' => $worker->id,
            ],
        );
    }

    private function buildPayloadFromJournalResource(
        ConstructionJournalEntry $entry,
        ?EstimateItem $estimateItem,
        float $quantity,
        string $linkField,
        int $linkId,
        ?string $notes,
        array $additionalInfo,
    ): array {
        $contractLink = $this->resolveContractLinkForEntry($entry, $estimateItem);
        [$price, $totalAmount] = $this->resolveFactPrice($estimateItem, $contractLink, $quantity);

        $contractId = $contractLink?->contract_id;
        $contractorId = $contractLink?->contract?->contractor_id;

        if (!$estimateItem && $entry->journal->contract_id) {
            $contractId = $entry->journal->contract_id;
            $contractorId = $entry->journal->contract?->contractor_id;
        }

        return [
            'organization_id' => $entry->journal->organization_id,
            'project_id' => $entry->journal->project_id,
            'schedule_task_id' => null,
            'estimate_item_id' => $estimateItem?->id,
            'journal_entry_id' => $entry->id,
            'journal_work_volume_id' => null,
            'journal_material_id' => null,
            'journal_equipment_id' => null,
            'journal_worker_id' => null,
            $linkField => $linkId,
            'work_origin_type' => CompletedWork::ORIGIN_JOURNAL,
            'planning_status' => CompletedWork::PLANNING_PLANNED,
            'contract_id' => $contractId,
            'work_type_id' => $estimateItem?->work_type_id,
            'user_id' => $entry->created_by_user_id,
            'contractor_id' => $contractorId,
            'quantity' => $quantity,
            'completed_quantity' => $quantity,
            'price' => $price,
            'total_amount' => $totalAmount,
            'completion_date' => $entry->entry_date,
            'notes' => $notes ?: $entry->work_description,
            'status' => $this->mapJournalStatusToCompletedWorkStatus($entry->status),
            'additional_info' => array_filter([
                ...$additionalInfo,
                'journal_entry_number' => $entry->entry_number,
                'journal_status' => $entry->status?->value,
                'weather_conditions' => $entry->weather_conditions,
            ], static fn ($value) => $value !== null && $value !== []),
        ];
    }

    private function resolveFactPrice(
        ?EstimateItem $estimateItem,
        ?ContractEstimateItem $contractLink,
        float $quantity,
    ): array {
        if ($contractLink && (float) $contractLink->quantity > 0) {
            $price = round((float) $contractLink->amount / (float) $contractLink->quantity, 2);

            return [$price, round($price * $quantity, 2)];
        }

        if ($estimateItem) {
            $estimatePrice = (float) (
                $estimateItem->actual_unit_price
                ?? $estimateItem->current_unit_price
                ?? $estimateItem->unit_price
                ?? 0
            );

            if ($estimatePrice > 0) {
                $price = round($estimatePrice, 2);

                return [$price, round($price * $quantity, 2)];
            }
        }

        if ($estimateItem) {
            $estimateQuantity = (float) ($estimateItem->quantity_total ?? $estimateItem->quantity ?? 0);
            $estimateAmount = (float) ($estimateItem->current_total_amount ?? $estimateItem->total_amount ?? 0);

            if ($estimateQuantity > 0 && $estimateAmount > 0) {
                $price = round($estimateAmount / $estimateQuantity, 2);

                return [$price, round($price * $quantity, 2)];
            }
        }

        return [null, null];
    }

    private function resolveContractLinkForEntry(ConstructionJournalEntry $entry, ?EstimateItem $estimateItem): ?ContractEstimateItem
    {
        if (!$estimateItem) {
            return null;
        }

        $estimateItem->loadMissing('contractLinks.contract.contractor');

        return $this->journalContractCoverageService->resolveContractLink(
            $entry->journal->contract_id,
            $estimateItem->contractLinks->sortBy('id')->values(),
        );
    }

    private function mapJournalStatusToCompletedWorkStatus(JournalEntryStatusEnum|string|null $status): string
    {
        $resolvedStatus = $status instanceof JournalEntryStatusEnum ? $status : JournalEntryStatusEnum::from((string) $status);

        return match ($resolvedStatus) {
            JournalEntryStatusEnum::DRAFT => 'draft',
            JournalEntryStatusEnum::SUBMITTED => 'in_review',
            JournalEntryStatusEnum::APPROVED => 'confirmed',
            JournalEntryStatusEnum::REJECTED => 'rejected',
        };
    }

    private function syncTaskById(int $taskId): void
    {
        $task = ScheduleTask::query()->find($taskId);

        if ($task) {
            $this->scheduleTaskCompletedWorkService->syncCompletedQuantity($task);
        }
    }

    private function backfillEntryScheduleTask(ConstructionJournalEntry $entry): void
    {
        if ($entry->schedule_task_id) {
            return;
        }

        $task = $this->scheduleTaskResolver->resolveUniqueTaskForEntry($entry);

        if (! $task) {
            return;
        }

        $entry->forceFill(['schedule_task_id' => $task->id])->save();
        $entry->setRelation('scheduleTask', $task);
    }

    private function resolveTaskName(CompletedWork $completedWork): string
    {
        $baseName = $completedWork->workType?->name;

        if (!$baseName && is_string($completedWork->notes) && trim($completedWork->notes) !== '') {
            $baseName = trim(mb_substr($completedWork->notes, 0, 120));
        }

        return $baseName ?: 'Внеплановая работа';
    }
}
