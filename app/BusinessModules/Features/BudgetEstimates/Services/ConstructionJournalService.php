<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Project;
use App\Models\User;
use App\Enums\ConstructionJournal\JournalStatusEnum;
use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Collection;

class ConstructionJournalService
{
    /**
     * Создать новый журнал работ для проекта
     */
    public function createJournal(Project $project, array $data, User $user): ConstructionJournal
    {
        return DB::transaction(function () use ($project, $data, $user) {
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

    /**
     * Обновить журнал работ
     */
    public function updateJournal(ConstructionJournal $journal, array $data): ConstructionJournal
    {
        $journal->update($data);
        return $journal->fresh(['project', 'contract', 'createdBy']);
    }

    /**
     * Удалить журнал работ
     */
    public function deleteJournal(ConstructionJournal $journal): bool
    {
        return DB::transaction(function () use ($journal) {
            // Записи удалятся автоматически через cascade
            return $journal->delete();
        });
    }

    /**
     * Создать запись в журнале работ
     */
    public function createEntry(ConstructionJournal $journal, array $data, User $user): ConstructionJournalEntry
    {
        return DB::transaction(function () use ($journal, $data, $user) {
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

            // Добавить связанные данные
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

            return $entry->load([
                'journal',
                'scheduleTask',
                'createdBy',
                'workVolumes',
                'workers',
                'equipment',
                'materials'
            ]);
        });
    }

    /**
     * Обновить запись журнала
     */
    public function updateEntry(ConstructionJournalEntry $entry, array $data): ConstructionJournalEntry
    {
        return DB::transaction(function () use ($entry, $data) {
            // Обновить основные данные
            $updateData = [];
            if (isset($data['schedule_task_id'])) {
                $updateData['schedule_task_id'] = $data['schedule_task_id'];
            }
            if (isset($data['estimate_id'])) {
                $updateData['estimate_id'] = $data['estimate_id'];
            }
            if (isset($data['entry_date'])) {
                $updateData['entry_date'] = $data['entry_date'];
            }
            if (isset($data['work_description'])) {
                $updateData['work_description'] = $data['work_description'];
            }
            if (isset($data['weather_conditions'])) {
                $updateData['weather_conditions'] = $data['weather_conditions'];
            }
            if (isset($data['problems_description'])) {
                $updateData['problems_description'] = $data['problems_description'];
            }
            if (isset($data['safety_notes'])) {
                $updateData['safety_notes'] = $data['safety_notes'];
            }
            if (isset($data['visitors_notes'])) {
                $updateData['visitors_notes'] = $data['visitors_notes'];
            }
            if (isset($data['quality_notes'])) {
                $updateData['quality_notes'] = $data['quality_notes'];
            }
            
            if (!empty($updateData)) {
                $entry->update($updateData);
            }

            // Обновить связанные данные если указаны
            if (isset($data['work_volumes'])) {
                $entry->workVolumes()->delete();
                $this->attachWorkVolumes($entry, $data['work_volumes']);
            }

            if (isset($data['workers'])) {
                $entry->workers()->delete();
                $this->attachWorkers($entry, $data['workers']);
            }

            if (isset($data['equipment'])) {
                $entry->equipment()->delete();
                $this->attachEquipment($entry, $data['equipment']);
            }

            if (isset($data['materials'])) {
                $entry->materials()->delete();
                $this->attachMaterials($entry, $data['materials']);
            }

            return $entry->fresh([
                'journal',
                'scheduleTask',
                'createdBy',
                'workVolumes',
                'workers',
                'equipment',
                'materials'
            ]);
        });
    }

    /**
     * Удалить запись журнала
     */
    public function deleteEntry(ConstructionJournalEntry $entry): bool
    {
        return DB::transaction(function () use ($entry) {
            // Связанные данные удалятся автоматически через cascade
            return $entry->delete();
        });
    }

    /**
     * Получить записи за конкретную дату
     */
    public function getDailyEntries(ConstructionJournal $journal, Carbon $date): Collection
    {
        return $journal->entries()
            ->byDate($date)
            ->with([
                'scheduleTask',
                'createdBy',
                'approvedBy',
                'workVolumes.estimateItem',
                'workers',
                'equipment',
                'materials'
            ])
            ->get();
    }

    /**
     * Получить записи за период
     */
    public function getEntriesForPeriod(ConstructionJournal $journal, Carbon $from, Carbon $to): Collection
    {
        return $journal->entries()
            ->byDateRange($from, $to)
            ->with([
                'scheduleTask',
                'createdBy',
                'approvedBy',
                'workVolumes.estimateItem',
                'workers',
                'equipment',
                'materials'
            ])
            ->get();
    }

    // === PROTECTED METHODS ===

    /**
     * Прикрепить объемы работ к записи
     */
    protected function attachWorkVolumes(ConstructionJournalEntry $entry, array $volumes): void
    {
        foreach ($volumes as $volume) {
            $entry->workVolumes()->create([
                'estimate_item_id' => $volume['estimate_item_id'] ?? null,
                'work_type_id' => $volume['work_type_id'] ?? null,
                'quantity' => $volume['quantity'],
                'measurement_unit_id' => $volume['measurement_unit_id'] ?? null,
                'notes' => $volume['notes'] ?? null,
            ]);
        }
    }

    /**
     * Прикрепить рабочих к записи
     */
    protected function attachWorkers(ConstructionJournalEntry $entry, array $workers): void
    {
        foreach ($workers as $worker) {
            $entry->workers()->create([
                'specialty' => $worker['specialty'],
                'workers_count' => $worker['workers_count'],
                'hours_worked' => $worker['hours_worked'] ?? null,
            ]);
        }
    }

    /**
     * Прикрепить оборудование к записи
     */
    protected function attachEquipment(ConstructionJournalEntry $entry, array $equipment): void
    {
        foreach ($equipment as $item) {
            $entry->equipment()->create([
                'equipment_name' => $item['equipment_name'],
                'equipment_type' => $item['equipment_type'] ?? null,
                'quantity' => $item['quantity'] ?? 1,
                'hours_used' => $item['hours_used'] ?? null,
            ]);
        }
    }

    /**
     * Прикрепить материалы к записи
     */
    protected function attachMaterials(ConstructionJournalEntry $entry, array $materials): void
    {
        foreach ($materials as $material) {
            $entry->materials()->create([
                'material_id' => $material['material_id'] ?? null,
                'material_name' => $material['material_name'],
                'quantity' => $material['quantity'],
                'measurement_unit' => $material['measurement_unit'],
                'notes' => $material['notes'] ?? null,
            ]);
        }
    }

    /**
     * Сгенерировать номер журнала
     */
    protected function generateJournalNumber(Project $project): string
    {
        $year = now()->year;
        $count = ConstructionJournal::where('project_id', $project->id)
            ->whereYear('created_at', $year)
            ->count() + 1;

        return "ОЖР-{$project->id}-{$year}-{$count}";
    }
}

