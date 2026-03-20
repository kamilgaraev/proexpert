<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

class ConstructionJournalPayloadService
{
    public function mapJournal(ConstructionJournal $journal, User $user, bool $includeEntries = false): array
    {
        $summary = $this->buildJournalSummary($journal);

        $payload = [
            'id' => $journal->id,
            'organization_id' => $journal->organization_id,
            'project_id' => $journal->project_id,
            'contract_id' => $journal->contract_id,
            'name' => $journal->name,
            'journal_number' => $journal->journal_number,
            'start_date' => optional($journal->start_date)?->format('Y-m-d'),
            'end_date' => optional($journal->end_date)?->format('Y-m-d'),
            'status' => $journal->status?->value ?? (string) $journal->status,
            'created_by_user_id' => $journal->created_by_user_id,
            'created_at' => optional($journal->created_at)?->toDateTimeString(),
            'updated_at' => optional($journal->updated_at)?->toDateTimeString(),
            'project' => $journal->relationLoaded('project') && $journal->project
                ? $this->mapProject($journal->project)
                : null,
            'contract' => $journal->relationLoaded('contract') && $journal->contract
                ? $this->mapContract($journal->contract)
                : null,
            'createdBy' => $journal->relationLoaded('createdBy') && $journal->createdBy
                ? $this->mapUser($journal->createdBy)
                : null,
            'total_entries' => $summary['total_entries'],
            'approved_entries' => $summary['approved_entries'],
            'submitted_entries' => $summary['submitted_entries'],
            'rejected_entries' => $summary['rejected_entries'],
            'available_actions' => $this->buildJournalActions($journal, $user),
        ];

        if ($includeEntries) {
            $entries = $journal->relationLoaded('entries') ? $journal->entries : collect();
            $payload['entries'] = $entries
                ->map(fn (ConstructionJournalEntry $entry): array => $this->mapEntry($entry, $user, false))
                ->values()
                ->all();
        }

        return $payload;
    }

    public function mapEntry(ConstructionJournalEntry $entry, User $user, bool $includeJournal = true): array
    {
        return [
            'id' => $entry->id,
            'journal_id' => $entry->journal_id,
            'schedule_task_id' => $entry->schedule_task_id,
            'estimate_id' => $entry->estimate_id,
            'entry_date' => optional($entry->entry_date)?->format('Y-m-d'),
            'entry_number' => $entry->entry_number,
            'work_description' => $entry->work_description,
            'status' => $entry->status?->value ?? (string) $entry->status,
            'created_by_user_id' => $entry->created_by_user_id,
            'approved_by_user_id' => $entry->approved_by_user_id,
            'approved_at' => optional($entry->approved_at)?->toDateTimeString(),
            'rejection_reason' => $entry->rejection_reason,
            'weather_conditions' => $entry->weather_conditions,
            'problems_description' => $entry->problems_description,
            'safety_notes' => $entry->safety_notes,
            'visitors_notes' => $entry->visitors_notes,
            'quality_notes' => $entry->quality_notes,
            'created_at' => optional($entry->created_at)?->toDateTimeString(),
            'updated_at' => optional($entry->updated_at)?->toDateTimeString(),
            'journal' => $includeJournal && $entry->relationLoaded('journal') && $entry->journal
                ? $this->mapJournalReference($entry->journal, $user)
                : null,
            'scheduleTask' => $entry->relationLoaded('scheduleTask') && $entry->scheduleTask
                ? $this->mapScheduleTask($entry->scheduleTask)
                : null,
            'estimate' => $entry->relationLoaded('estimate') && $entry->estimate
                ? $this->mapEstimate($entry->estimate)
                : null,
            'createdBy' => $entry->relationLoaded('createdBy') && $entry->createdBy
                ? $this->mapUser($entry->createdBy)
                : null,
            'approvedBy' => $entry->relationLoaded('approvedBy') && $entry->approvedBy
                ? $this->mapUser($entry->approvedBy)
                : null,
            'workVolumes' => $entry->relationLoaded('workVolumes')
                ? $entry->workVolumes->map(fn ($volume): array => [
                    'id' => $volume->id,
                    'journal_entry_id' => $volume->journal_entry_id,
                    'estimate_item_id' => $volume->estimate_item_id,
                    'work_type_id' => $volume->work_type_id,
                    'quantity' => (float) $volume->quantity,
                    'measurement_unit_id' => $volume->measurement_unit_id,
                    'notes' => $volume->notes,
                    'estimateItem' => $volume->relationLoaded('estimateItem') && $volume->estimateItem
                        ? [
                            'id' => $volume->estimateItem->id,
                            'estimate_id' => $volume->estimateItem->estimate_id,
                            'name' => $volume->estimateItem->name,
                            'quantity_total' => (float) $volume->estimateItem->quantity_total,
                        ]
                        : null,
                    'workType' => $volume->relationLoaded('workType') && $volume->workType
                        ? [
                            'id' => $volume->workType->id,
                            'name' => $volume->workType->name,
                        ]
                        : null,
                    'measurementUnit' => $volume->relationLoaded('measurementUnit') && $volume->measurementUnit
                        ? [
                            'id' => $volume->measurementUnit->id,
                            'name' => $volume->measurementUnit->name,
                            'short_name' => $volume->measurementUnit->short_name,
                        ]
                        : null,
                ])->values()->all()
                : [],
            'workers' => $entry->relationLoaded('workers')
                ? $entry->workers->map(fn ($worker): array => [
                    'id' => $worker->id,
                    'journal_entry_id' => $worker->journal_entry_id,
                    'specialty' => $worker->specialty,
                    'workers_count' => $worker->workers_count,
                    'hours_worked' => $worker->hours_worked !== null ? (float) $worker->hours_worked : null,
                ])->values()->all()
                : [],
            'equipment' => $entry->relationLoaded('equipment')
                ? $entry->equipment->map(fn ($equipment): array => [
                    'id' => $equipment->id,
                    'journal_entry_id' => $equipment->journal_entry_id,
                    'equipment_name' => $equipment->equipment_name,
                    'equipment_type' => $equipment->equipment_type,
                    'quantity' => $equipment->quantity,
                    'hours_used' => $equipment->hours_used !== null ? (float) $equipment->hours_used : null,
                ])->values()->all()
                : [],
            'materials' => $entry->relationLoaded('materials')
                ? $entry->materials->map(fn ($material): array => [
                    'id' => $material->id,
                    'journal_entry_id' => $material->journal_entry_id,
                    'material_id' => $material->material_id,
                    'material_name' => $material->material_name,
                    'quantity' => (float) $material->quantity,
                    'measurement_unit' => $material->measurement_unit,
                    'notes' => $material->notes,
                    'material' => $material->relationLoaded('material') && $material->material
                        ? [
                            'id' => $material->material->id,
                            'name' => $material->material->name,
                        ]
                        : null,
                ])->values()->all()
                : [],
            'available_actions' => $this->buildEntryActions($entry, $user),
        ];
    }

    public function buildJournalSummary(ConstructionJournal $journal): array
    {
        return [
            'total_entries' => $this->resolveCount($journal, 'entries_count', fn (): int => $journal->entries()->count()),
            'approved_entries' => $this->resolveCount($journal, 'approved_entries_count', fn (): int => $journal->entries()->approved()->count()),
            'submitted_entries' => $this->resolveCount($journal, 'submitted_entries_count', fn (): int => $journal->entries()->submitted()->count()),
            'rejected_entries' => $this->resolveCount($journal, 'rejected_entries_count', fn (): int => $journal->entries()->rejected()->count()),
        ];
    }

    public function buildEntrySummary(ConstructionJournal $journal): array
    {
        return $this->buildJournalSummary($journal);
    }

    public function buildJournalActions(Project|ConstructionJournal $subject, User $user): array
    {
        $actions = ['view'];

        if ($subject instanceof Project) {
            if (Gate::forUser($user)->allows('create', [ConstructionJournal::class, $subject])) {
                $actions[] = 'create';
            }

            return $actions;
        }

        if (Gate::forUser($user)->allows('update', $subject)) {
            $actions[] = 'update';
        }

        if (Gate::forUser($user)->allows('delete', $subject)) {
            $actions[] = 'delete';
        }

        if (Gate::forUser($user)->allows('export', $subject)) {
            $actions[] = 'export';
        }

        if (Gate::forUser($user)->allows('create', [ConstructionJournalEntry::class, $subject])) {
            $actions[] = 'create_entry';
        }

        return array_values(array_unique($actions));
    }

    public function buildEntryActions(ConstructionJournalEntry $entry, User $user): array
    {
        $actions = ['view'];

        if (Gate::forUser($user)->allows('update', $entry)) {
            $actions[] = 'update';
            if ($entry->status?->canSubmit()) {
                $actions[] = 'submit';
            }
        }

        if (Gate::forUser($user)->allows('delete', $entry)) {
            $actions[] = 'delete';
        }

        if (Gate::forUser($user)->allows('approve', $entry)) {
            if ($entry->status?->canApprove()) {
                $actions[] = 'approve';
            }

            if ($entry->status?->canReject()) {
                $actions[] = 'reject';
            }
        }

        if (Gate::forUser($user)->allows('export', $entry->journal)) {
            $actions[] = 'export_daily_report';
        }

        return array_values(array_unique($actions));
    }

    public function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }

    private function resolveCount(ConstructionJournal $journal, string $attribute, callable $fallback): int
    {
        $value = $journal->getAttribute($attribute);

        if ($value !== null) {
            return (int) $value;
        }

        return (int) $fallback();
    }

    private function mapProject(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
        ];
    }

    private function mapContract(object $contract): array
    {
        return [
            'id' => $contract->id,
            'number' => $contract->number ?? null,
            'subject' => $contract->subject ?? null,
        ];
    }

    private function mapUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }

    private function mapScheduleTask(object $task): array
    {
        return [
            'id' => $task->id,
            'name' => $task->name ?? null,
            'progress_percent' => $task->progress_percent !== null ? (float) $task->progress_percent : null,
            'estimate_item_id' => $task->estimate_item_id ?? null,
            'quantity' => $task->quantity !== null ? (float) $task->quantity : null,
        ];
    }

    private function mapEstimate(object $estimate): array
    {
        return [
            'id' => $estimate->id,
            'project_id' => $estimate->project_id ?? null,
            'contract_id' => $estimate->contract_id ?? null,
            'name' => $estimate->name ?? null,
            'number' => $estimate->number ?? null,
        ];
    }

    private function mapJournalReference(ConstructionJournal $journal, User $user): array
    {
        $summary = $this->buildJournalSummary($journal);

        return [
            'id' => $journal->id,
            'organization_id' => $journal->organization_id,
            'project_id' => $journal->project_id,
            'contract_id' => $journal->contract_id,
            'name' => $journal->name,
            'journal_number' => $journal->journal_number,
            'start_date' => optional($journal->start_date)?->format('Y-m-d'),
            'end_date' => optional($journal->end_date)?->format('Y-m-d'),
            'status' => $journal->status?->value ?? (string) $journal->status,
            'created_by_user_id' => $journal->created_by_user_id,
            'created_at' => optional($journal->created_at)?->toDateTimeString(),
            'updated_at' => optional($journal->updated_at)?->toDateTimeString(),
            'project' => $journal->relationLoaded('project') && $journal->project
                ? $this->mapProject($journal->project)
                : null,
            'contract' => $journal->relationLoaded('contract') && $journal->contract
                ? $this->mapContract($journal->contract)
                : null,
            'createdBy' => $journal->relationLoaded('createdBy') && $journal->createdBy
                ? $this->mapUser($journal->createdBy)
                : null,
            'total_entries' => $summary['total_entries'],
            'approved_entries' => $summary['approved_entries'],
            'submitted_entries' => $summary['submitted_entries'],
            'rejected_entries' => $summary['rejected_entries'],
            'available_actions' => $this->buildJournalActions($journal, $user),
        ];
    }
}
