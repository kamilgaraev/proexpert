<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Services;

use App\Models\CompletedWork;
use App\Models\ConstructionJournalEntry;
use App\Models\Project;
use App\Models\User;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Services\Project\UserProjectAccessService;
use App\Exceptions\BusinessLogicException;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ExecutiveDocumentReferenceService
{
    private const MAX_PER_PAGE = 100;

    public function __construct(private readonly AuthorizationService $authorization, private readonly UserProjectAccessService $access, private readonly HiddenWorkActAutofillService $autofill) {}

    /** @param array<string, mixed> $filters */
    public function paginate(int $organizationId, array $filters, int $userId): LengthAwarePaginator
    {
        $projectId = (int) $filters['project_id'];
        $actor = User::query()->find($userId);
        $project = Project::query()->find($projectId);
        if ($actor === null || $project === null || (int) $actor->current_organization_id !== $organizationId
            || !$actor->belongsToOrganization($organizationId) || !$this->access->canAccessProject($actor, $project, $organizationId)) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.project_not_found'), 404);
        }
        if (!$this->authorization->can($actor, 'executive-documentation.view', ['organization_id' => $organizationId, 'project_id' => $projectId, 'strict_project_scope' => true])) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.forbidden'), 403);
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), self::MAX_PER_PAGE);
        $page = max((int) ($filters['page'] ?? 1), 1);

        return match ($filters['reference_type']) {
            'completed_works' => $this->paginateCompletedWorks($organizationId, $projectId, $filters, $perPage, $page),
            'journal_entries' => $this->paginateJournalEntries($organizationId, $projectId, $filters, $perPage, $page),
            default => throw new DomainException(trans_message('executive_documentation.errors.reference_type_invalid')),
        };
    }

    /** @param array<string, mixed> $filters */
    private function paginateCompletedWorks(int $organizationId, int $projectId, array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        $query = CompletedWork::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->with([
                'workType:id,name',
                'journalEntry.journal:id,name,journal_number',
                'journalEntry.workVolumes.workType:id,name',
                'journalEntry.workVolumes.estimateItem:id,name,position_number',
                'journalEntry.workVolumes.measurementUnit:id,name,short_name',
                'journalEntry.materials:id,journal_entry_id,material_name,quantity,measurement_unit',
                'journalWorkVolume.workType:id,name',
                'journalWorkVolume.estimateItem:id,name,position_number',
                'journalWorkVolume.measurementUnit:id,name,short_name',
            ]);

        $this->applyCompletedWorkFilters($query, $filters);

        return $query->latest('completion_date')->latest('id')->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (CompletedWork $work): array => [
                'id' => $work->id,
                'name' => $work->workType?->name ?? $work->notes ?? ('Работа #' . $work->id),
                'work_type_name' => $work->workType?->name,
                'completion_date' => $work->completion_date?->format('Y-m-d'),
                'quantity' => $work->quantity,
                'completed_quantity' => $work->completed_quantity,
                'status' => $work->status,
                'notes' => $work->notes,
                'description' => $work->description,
                'journal_entry_id' => $work->journal_entry_id,
                'journal_entry_number' => $work->journalEntry?->entry_number,
                'hidden_work_act_defaults' => $this->autofill->forCompletedWorkReference($work, $organizationId),
            ]);
    }

    /** @param array<string, mixed> $filters */
    private function paginateJournalEntries(int $organizationId, int $projectId, array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        $query = ConstructionJournalEntry::query()
            ->whereHas('journal', static fn ($journal) => $journal
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId))
            ->with([
                'journal:id,name,journal_number',
                'workVolumes.workType:id,name',
                'workVolumes.estimateItem:id,name,position_number',
                'workVolumes.measurementUnit:id,name,short_name',
                'materials:id,journal_entry_id,material_name,quantity,measurement_unit',
                'completedWorks.workType:id,name',
                'completedWorks.journalWorkVolume.workType:id,name',
                'completedWorks.journalWorkVolume.estimateItem:id,name,position_number',
                'completedWorks.journalWorkVolume.measurementUnit:id,name,short_name',
            ]);

        $this->applyJournalEntryFilters($query, $filters);

        return $query->select('construction_journal_entries.*')
            ->latest('entry_date')
            ->latest('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (ConstructionJournalEntry $entry): array => [
                'id' => $entry->id,
                'journal_id' => $entry->journal_id,
                'journal_name' => $entry->journal?->name,
                'journal_number' => $entry->journal?->journal_number,
                'entry_number' => $entry->entry_number,
                'entry_date' => $entry->entry_date?->format('Y-m-d'),
                'work_description' => $entry->work_description,
                'hidden_work_act_defaults' => $this->autofill->forJournalEntryReference($entry, $organizationId),
                'status' => $entry->status?->value ?? $entry->status,
            ]);
    }

    /** @param \Illuminate\Database\Eloquent\Builder<CompletedWork> $query @param array<string, mixed> $filters */
    private function applyCompletedWorkFilters($query, array $filters): void
    {
        $this->applyDateFilters($query, 'completion_date', $filters);
        $this->applySearch($query, $filters['search'] ?? null, [
            'notes',
            'description',
        ], 'workType');
    }

    /** @param \Illuminate\Database\Eloquent\Builder<ConstructionJournalEntry> $query @param array<string, mixed> $filters */
    private function applyJournalEntryFilters($query, array $filters): void
    {
        $this->applyDateFilters($query, 'entry_date', $filters);
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search === '') {
            return;
        }

        $like = '%' . $search . '%';
        $query->where(static function ($builder) use ($search, $like): void {
            if (ctype_digit($search)) {
                $builder->orWhere('id', (int) $search)
                    ->orWhere('entry_number', (int) $search);
            } else {
                $builder->whereRaw('1 = 0');
            }
            $builder
                ->orWhere('work_description', 'ilike', $like)
                ->orWhereHas('journal', static function ($journal) use ($like): void {
                    $journal->where(static function ($related) use ($like): void {
                        $related->where('name', 'ilike', $like)
                            ->orWhere('journal_number', 'ilike', $like);
                    });
                });
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $search) === 1) {
                $builder->orWhereDate('entry_date', $search);
            }
        });
    }

    /** @param array<string, mixed> $filters */
    private function applyDateFilters($query, string $column, array $filters): void
    {
        if (! empty($filters['date'])) {
            $query->whereDate($column, $filters['date']);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate($column, '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate($column, '<=', $filters['date_to']);
        }
    }

    /** @param list<string> $columns */
    private function applySearch($query, mixed $rawSearch, array $columns, string $relation): void
    {
        $search = trim((string) $rawSearch);
        if ($search === '') {
            return;
        }
        $like = '%' . $search . '%';
        $query->where(static function ($builder) use ($search, $like, $columns, $relation): void {
            if (ctype_digit($search)) {
                $builder->orWhere('id', (int) $search);
            } else {
                $builder->whereRaw('1 = 0');
            }
            foreach ($columns as $column) {
                $builder->orWhere($column, 'ilike', $like);
            }
            $builder->orWhereHas($relation, static fn ($related) => $related->where('name', 'ilike', $like));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $search) === 1) {
                $builder->orWhereDate('completion_date', $search);
            }
        });
    }
}
