<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\BusinessModules\Features\BudgetEstimates\Services\ConstructionJournalPayloadService;
use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use App\Enums\ConstructionJournal\JournalStatusEnum;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Project;
use App\Models\User;
use App\Services\ConstructionJournal\ConstructionJournalAccessService;
use App\Services\ConstructionJournal\ConstructionJournalFormOptionsService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;

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
        'close',
        'archive',
        'reopen',
    ];

    public function __construct(
        private readonly ConstructionJournalPayloadService $payloadService,
        private readonly ConstructionJournalAccessService $access,
        private readonly ConstructionJournalFormOptionsService $formOptions,
        private readonly MobileProjectAccessResolver $projectAccess,
    ) {}

    public function resolveProject(User $user, ?int $projectId): Project
    {
        $organizationId = (int) $user->current_organization_id;

        if ($organizationId <= 0) {
            throw new DomainException(trans_message('mobile_construction_journal.errors.no_organization'));
        }

        if ($projectId === null || $projectId <= 0) {
            throw new DomainException(trans_message('mobile_construction_journal.errors.project_not_found'));
        }

        return $this->projectAccess->resolve(
            $user,
            $organizationId,
            $projectId,
            trans_message('mobile_construction_journal.errors.project_not_found'),
        );
    }

    public function assertJournalAccess(User $user, ConstructionJournal $journal): void
    {
        $this->access->assertReadable($user, $journal);
    }

    public function buildJournalList(User $user, Project $project, int $page = 1, int $perPage = 15, ?string $status = null): array
    {
        if (! $this->access->canAccessProject($user, $project)
            || ! $this->access->hasPermission($user, $project, ['view', '*'])) {
            throw new AuthorizationException(trans_message('errors.unauthorized'));
        }

        $visibleJournals = $project->journals()
            ->where('organization_id', $project->organization_id)
            ->whereIn('performing_organization_id', $this->access->visiblePerformerIds($user, $project));
        $journals = (clone $visibleJournals)
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
                'active_journals' => (clone $visibleJournals)->where('status', 'active')->count(),
                'archived_journals' => (clone $visibleJournals)->where('status', 'archived')->count(),
                'closed_journals' => (clone $visibleJournals)->where('status', 'closed')->count(),
            ],
            'available_actions' => $this->mapActionList($this->payloadService->buildJournalActions($project, $user)),
        ];
    }

    public function buildEntriesList(User $user, ConstructionJournal $journal, array $filters): array
    {
        $this->assertJournalAccess($user, $journal);

        $query = $journal->entries()
            ->with(ConstructionJournalPayloadService::ENTRY_RELATIONS);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['date'])) {
            $query->whereDate('entry_date', $filters['date']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('entry_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('entry_date', '<=', $filters['date_to']);
        }

        $entries = $query->orderByDesc('entry_date')
            ->orderByDesc('entry_number')
            ->paginate(
                min(100, max(1, (int) ($filters['per_page'] ?? 20))),
                ['*'],
                'page',
                max(1, (int) ($filters['page'] ?? 1))
            );

        $this->payloadService->prepareEntryPage($entries->getCollection());

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
        return $this->formOptions->build($user, $journal);
    }

    public function mapMobileJournal(ConstructionJournal $journal, User $user, bool $includeEntries = false): array
    {
        $this->assertJournalAccess($user, $journal);

        return $this->transformJournalPayload($this->payloadService->mapJournal($journal, $user, $includeEntries));
    }

    public function mapMobileEntry(ConstructionJournalEntry $entry, User $user, bool $includeJournal = true): array
    {
        $this->assertJournalAccess($user, $entry->journal);

        return $this->transformEntryPayload($this->payloadService->mapEntry($entry, $user, $includeJournal));
    }

    private function transformJournalPayload(array $payload): array
    {
        $status = JournalStatusEnum::tryFrom($this->requiredPayloadString($payload, 'status'));

        if (! $status) {
            throw new DomainException(trans_message('mobile_construction_journal.errors.invalid_status'));
        }

        $payload['status'] = $status->value;
        $payload['status_label'] = trans_message('mobile_construction_journal.statuses.journal.'.$status->value);
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

        if (! $status) {
            throw new DomainException(trans_message('mobile_construction_journal.errors.invalid_status'));
        }

        $payload['status'] = $status->value;
        $payload['status_label'] = trans_message('mobile_construction_journal.statuses.entry.'.$status->value);
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
        $title = trim((string) ($volume['estimateItem']['name'] ?? ''));
        if ($title === '') {
            $title = trim((string) ($volume['work_name'] ?? ''));
        }
        if ($title === '') {
            $title = trim((string) ($volume['workType']['name'] ?? ''));
        }
        if ($title === '') {
            $title = trans_message('mobile_construction_journal.labels.work_volume_unnamed');
        }
        $measurementUnitName = trim((string) (
            $volume['measurementUnit']['short_name']
            ?? $volume['measurementUnit']['name']
            ?? ''
        ));

        $volume['title'] = $title;
        $volume['measurement_unit_name'] = $measurementUnitName !== '' ? $measurementUnitName : null;

        return $volume;
    }

    private function mapActionList(array $actions): array
    {
        return collect($actions)
            ->map(function (mixed $action): array {
                $key = (string) $action;

                if (! in_array($key, self::ACTIONS, true)) {
                    throw new DomainException(trans_message('mobile_construction_journal.errors.invalid_action'));
                }

                return [
                    'action' => $key,
                    'label' => trans_message('mobile_construction_journal.actions.'.$key),
                ];
            })
            ->values()
            ->all();
    }

    private function requiredPayloadString(array $payload, string $key): string
    {
        $value = trim((string) ($payload[$key] ?? ''));

        if ($value === '') {
            throw new DomainException(trans_message('mobile_construction_journal.errors.invalid_status'));
        }

        return $value;
    }

    public function buildJournalFormOptions(User $user, Project $project): array
    {
        return $this->formOptions->buildJournalFormOptions($user, $project);
    }

    private function requiredPayloadArray(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;

        if (! is_array($value)) {
            throw new DomainException(trans_message('mobile_construction_journal.errors.incomplete_payload'));
        }

        return $value;
    }
}
