<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\BusinessModules\Features\BudgetEstimates\Services\ConstructionJournalPayloadService;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Project;
use App\Models\User;
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
}
