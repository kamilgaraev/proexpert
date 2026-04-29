<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

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
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ConstructionJournalService
{
    public function __construct(
        private readonly CompletedWorkFactService $completedWorkFactService,
        private readonly JournalContractCoverageService $journalContractCoverageService,
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

            return $journal->load(['project', 'contract', 'createdBy']);
        });
    }

    public function updateJournal(ConstructionJournal $journal, array $data): ConstructionJournal
    {
        if (array_key_exists('contract_id', $data)) {
            $this->assertContractScope($journal->project, $data['contract_id']);
        }

        $journal->update($data);

        return $journal->fresh(['project', 'contract', 'createdBy']);
    }

    public function deleteJournal(ConstructionJournal $journal): bool
    {
        return DB::transaction(fn (): bool => (bool) $journal->delete());
    }

    public function createEntry(ConstructionJournal $journal, array $data, User $user): ConstructionJournalEntry
    {
        return DB::transaction(function () use ($journal, $data, $user): ConstructionJournalEntry {
            $this->assertEntryScope($journal, $data);

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

            return $entry->load([
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
        });
    }

    public function updateEntry(ConstructionJournalEntry $entry, array $data): ConstructionJournalEntry
    {
        return DB::transaction(function () use ($entry, $data): ConstructionJournalEntry {
            $this->assertEntryScope($entry->journal, $data, $entry);

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

            return $entry->fresh([
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
        });
    }

    public function deleteEntry(ConstructionJournalEntry $entry): bool
    {
        return DB::transaction(function () use ($entry): bool {
            $this->completedWorkFactService->deleteJournalEntryFacts($entry);

            return (bool) $entry->delete();
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

            if (($volume['auto_attach_contract_coverage'] ?? false) && $estimateItemId) {
                $estimateItem = EstimateItem::query()
                    ->with(['estimate', 'contractLinks.contract.contractor'])
                    ->find($estimateItemId);

                if ($estimateItem) {
                    $this->journalContractCoverageService->ensureCoverage($entry->journal, $estimateItem);
                }
            }

            $entry->workVolumes()->create([
                'estimate_item_id' => $estimateItemId,
                'work_type_id' => $volume['work_type_id'] ?? null,
                'quantity' => $volume['quantity'],
                'measurement_unit_id' => $volume['measurement_unit_id'] ?? null,
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

            if (($volume['auto_attach_contract_coverage'] ?? false) && $estimateItemId) {
                $estimateItem = EstimateItem::query()
                    ->with(['estimate', 'contractLinks.contract.contractor'])
                    ->find($estimateItemId);

                if ($estimateItem) {
                    $this->journalContractCoverageService->ensureCoverage($entry->journal, $estimateItem);
                }
            }

            $payload = [
                'estimate_item_id' => $estimateItemId,
                'work_type_id' => $volume['work_type_id'] ?? null,
                'quantity' => $volume['quantity'],
                'measurement_unit_id' => $volume['measurement_unit_id'] ?? null,
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
            $entry->materials()->create([
                'material_id' => $material['material_id'] ?? null,
                'estimate_item_id' => $material['estimate_item_id'] ?? null,
                'material_name' => $material['material_name'],
                'quantity' => $material['quantity'],
                'measurement_unit' => $material['measurement_unit'],
                'notes' => $material['notes'] ?? null,
            ]);
        }
    }

    protected function generateJournalNumber(Project $project): string
    {
        $year = now()->year;
        $count = ConstructionJournal::where('project_id', $project->id)
            ->whereYear('created_at', $year)
            ->count() + 1;

        return "РћР–Р -{$project->id}-{$year}-{$count}";
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

    protected function assertEntryScope(ConstructionJournal $journal, array $data, ?ConstructionJournalEntry $entry = null): void
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
}
