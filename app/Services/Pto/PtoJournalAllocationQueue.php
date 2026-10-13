<?php

declare(strict_types=1);

namespace App\Services\Pto;

use App\Exceptions\BusinessLogicException;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Project;
use App\Models\User;
use App\Services\ConstructionJournal\ConstructionJournalAccessService;
use App\Services\ConstructionJournal\JournalContractAssignmentService;
use App\Services\ConstructionJournal\JournalFieldVisibility;
use Illuminate\Database\Eloquent\Builder;

final class PtoJournalAllocationQueue
{
    public function __construct(
        private readonly ConstructionJournalAccessService $access,
        private readonly JournalFieldVisibility $visibility,
    ) {}

    public function list(User $actor, int $organizationId, array $filters): array
    {
        $project = Project::query()->find((int) ($filters['project_id'] ?? 0));
        if (! $project || $organizationId !== (int) $actor->current_organization_id
            || ! $this->access->canAccessProject($actor, $project)) {
            throw new BusinessLogicException(trans_message('construction_journal.errors.access_denied'), 404);
        }
        if (! $this->visibility->allows($actor, (int) $project->id, 'executive-documentation.view')
            || ! $this->access->hasPermission($actor, $project, ['view'])
            || ! $this->access->hasPermission($actor, $project, ['approve'])) {
            throw new BusinessLogicException(trans_message('construction_journal.errors.access_denied'), 403);
        }
        $journals = ConstructionJournal::query()->where('project_id', $project->id)
            ->where('organization_id', $project->organization_id)
            ->whereIn('performing_organization_id', $this->access->visiblePerformerIds($actor, $project))->get();
        $eligible = [];
        foreach ($journals as $journal) {
            $journal->setRelation('project', $project);
            $probe = new ConstructionJournalEntry(['created_by_user_id' => 0]);
            $probe->setRelation('journal', $journal);
            if (! $this->access->canApprove($actor, $probe)) {
                continue;
            }
            $probe->created_by_user_id = $actor->id;
            $eligible[(int) $journal->id] = $this->access->canApprove($actor, $probe);
        }
        $query = ConstructionJournalEntry::query()->where('status', 'approved')->whereNull('source_entry_id')
            ->where(function (Builder $query) use ($eligible, $actor): void {
                $query->whereRaw('1 = 0');
                foreach ($eligible as $journalId => $canOwn) {
                    $query->orWhere(function (Builder $entry) use ($journalId, $canOwn, $actor): void {
                        $entry->where('journal_id', $journalId);
                        if (! $canOwn) {
                            $entry->where('created_by_user_id', '<>', $actor->id);
                        }
                    });
                }
            })->where(function (Builder $query): void {
                foreach (JournalContractAssignmentService::RELATIONS as $relation) {
                    $query->orWhereHas($relation, fn (Builder $row) => $row->whereNull('contract_id'));
                }
            });
        $summary = ['unassigned_rows' => 0, 'entries' => (clone $query)->count()];
        $counts = [];
        foreach (JournalContractAssignmentService::RELATIONS as $relation) {
            $counts[$relation.' as unassigned_'.$relation.'_count'] = fn (Builder $row) => $row->whereNull('contract_id');
            $model = (new ConstructionJournalEntry)->{$relation}()->getRelated();
            $summary['unassigned_rows'] += $model->newQuery()->whereNull('contract_id')
                ->whereIn('journal_entry_id', (clone $query)->select('construction_journal_entries.id'))->count();
        }
        $page = $query->with('journal:id,name,journal_number')->withCount($counts)
            ->orderBy('entry_date')->orderBy('id')->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 25))), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
        $page->setCollection($page->getCollection()->map(static fn (ConstructionJournalEntry $entry): array => [
            'id' => $entry->id,
            'journal_id' => $entry->journal_id,
            'journal_name' => $entry->journal->name,
            'journal_number' => $entry->journal->journal_number,
            'entry_number' => $entry->entry_number,
            'entry_date' => $entry->entry_date?->format('Y-m-d'),
            'work_description' => $entry->work_description,
            'unassigned_rows' => (int) $entry->getAttribute('unassigned_workVolumes_count') + (int) $entry->getAttribute('unassigned_materials_count') + (int) $entry->getAttribute('unassigned_equipment_count') + (int) $entry->getAttribute('unassigned_workers_count'),
        ]));

        return ['paginator' => $page, 'summary' => $summary];
    }
}
