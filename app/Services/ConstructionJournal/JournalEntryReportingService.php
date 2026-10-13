<?php

declare(strict_types=1);

namespace App\Services\ConstructionJournal;

use App\BusinessModules\Features\BudgetEstimates\Services\ConstructionJournalPayloadService;
use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use App\Enums\ConstructionJournal\JournalStatusEnum;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Project;
use App\Models\User;
use App\Services\Logging\LoggingService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class JournalEntryReportingService
{
    public function __construct(
        private readonly ConstructionJournalAccessService $access,
        private readonly LoggingService $logging,
    ) {}

    public function canInclude(User $actor, ConstructionJournalEntry $source): bool
    {
        try {
            $this->assertEligible($actor, $source);
            return true;
        } catch (AuthorizationException|DomainException) {
            return false;
        }
    }

    public function targets(User $actor, ConstructionJournalEntry $source): array
    {
        $this->assertEligible($actor, $source);
        $journals = ConstructionJournal::query()
            ->where('project_id', $source->journal->project_id)
            ->where('organization_id', $source->journal->organization_id)
            ->where('performing_organization_id', (int) $actor->current_organization_id)
            ->where('status', JournalStatusEnum::ACTIVE)
            ->with('project', 'contract')
            ->orderBy('journal_number')->orderBy('id')->get();

        return $journals->filter(fn (ConstructionJournal $journal): bool => Gate::forUser($actor)->allows('create', [ConstructionJournalEntry::class, $journal]))
            ->map(fn (ConstructionJournal $journal): array => app(JournalFieldVisibility::class)->journal([
                'id' => $journal->id, 'name' => $journal->name, 'journal_number' => $journal->journal_number,
                'contract_number' => $journal->contract?->number,
            ], $actor, $journal->project))->values()->all();
    }

    public function include(User $actor, ConstructionJournalEntry $source, int $targetJournalId, array $data = []): ConstructionJournalEntry
    {
        $this->assertEligible($actor, $source);

        return DB::transaction(function () use ($actor, $source, $targetJournalId, $data): ConstructionJournalEntry {
            $target = ConstructionJournal::query()->whereKey($targetJournalId)->lockForUpdate()->firstOrFail();
            $project = Project::query()->whereKey($target->project_id)->lockForUpdate()->firstOrFail();
            $target->setRelation('project', $project);
            $source = ConstructionJournalEntry::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            $source->load('journal.project', 'journal.performingOrganization', 'approvedBy');
            $this->assertEligible($actor, $source);
            if ((int) $target->project_id !== (int) $source->journal->project_id
                || (int) $target->organization_id !== (int) $source->journal->organization_id
                || (int) $target->performing_organization_id !== (int) $actor->current_organization_id
                || $target->status !== JournalStatusEnum::ACTIVE
                || ! Gate::forUser($actor)->allows('create', [ConstructionJournalEntry::class, $target])) {
                throw new AuthorizationException(trans_message('construction_journal.errors.access_denied'));
            }
            $target->loadMissing('contract.projects');
            if ($target->contract_id !== null && (! $target->contract || (int) $target->contract->organization_id !== (int) $target->organization_id
                || ((int) $target->contract->project_id !== (int) $target->project_id
                    && ! $target->contract->projects->contains('id', $target->project_id)))) {
                throw new DomainException(trans_message('construction_journal.errors.reporting_unavailable'));
            }
            $existing = ConstructionJournalEntry::query()->where('journal_id', $target->id)->where('source_entry_id', $source->id)->first();
            if ($existing) {
                return $existing;
            }
            $payload = app(ConstructionJournalPayloadService::class)->snapshotEntry($source, $actor);
            $snapshot = array_intersect_key($payload, array_flip([
                'work_description', 'weather_conditions', 'problems_description', 'safety_notes', 'visitors_notes', 'quality_notes',
                'workVolumes', 'workers', 'equipment', 'materials',
            ]));
            $snapshot['source'] = [
                'id' => $source->id, 'journal_id' => $source->journal_id, 'entry_number' => $source->entry_number,
                'journal_name' => $source->journal->name, 'journal_number' => $source->journal->journal_number,
                'entry_date' => $source->entry_date?->format('Y-m-d'),
                'organization_name' => $source->journal->performingOrganization?->name,
                'approved_by_name' => $source->approvedBy?->name, 'approved_at' => $source->approved_at?->toIso8601String(),
            ];
            $snapshot['origin'] = $source->source_entry_snapshot['origin'] ?? $snapshot['source'];
            $entry = new ConstructionJournalEntry([
                'journal_id' => $target->id, 'entry_number' => $target->getNextEntryNumber(),
                'entry_date' => $data['entry_date'] ?? now()->toDateString(),
                'work_description' => $source->work_description,
                'quality_notes' => $data['comment'] ?? null,
                'status' => JournalEntryStatusEnum::DRAFT, 'created_by_user_id' => $actor->id,
                'weather_conditions' => $snapshot['weather_conditions'] ?? null,
            ]);
            $entry->source_entry_id = $source->id;
            $entry->source_entry_snapshot = $snapshot;
            $entry->setRelation('journal', $target);
            $this->assertSnapshot($entry);
            $entry->save();
            $this->logging->audit('construction_journal_entry.included', [
                'organization_id' => $target->organization_id, 'actor_organization_id' => (int) $actor->current_organization_id,
                'project_id' => $target->project_id, 'journal_id' => $target->id, 'journal_entry_id' => $entry->id,
                'source_entry_id' => $source->id, 'performed_by' => $actor->id,
            ]);
            return $entry;
        });
    }

    public function assertReady(ConstructionJournalEntry $entry): void
    {
        $this->assertSnapshot($entry);
        $entry->loadMissing('sourceEntry.journal', 'journal.contract.projects');
        $source = $entry->sourceEntry;
        if (! $source || $source->id === $entry->id || $source->status !== JournalEntryStatusEnum::APPROVED
            || ! $source->journal || ! $entry->journal
            || (int) $source->journal->project_id !== (int) $entry->journal->project_id
            || (int) $source->journal->organization_id !== (int) $entry->journal->organization_id
            || $this->access->approvalOrganizationId($source->journal) !== (int) $entry->journal->performing_organization_id
            || ($entry->journal->contract_id !== null && (! $entry->journal->contract
            || (int) $entry->journal->contract->organization_id !== (int) $entry->journal->organization_id
            || ((int) $entry->journal->contract->project_id !== (int) $entry->journal->project_id
                && ! $entry->journal->contract->projects->contains('id', $entry->journal->project_id))))
            || $entry->workVolumes()->exists() || $entry->workers()->exists() || $entry->equipment()->exists() || $entry->materials()->exists()) {
            throw new DomainException(trans_message('construction_journal.errors.reporting_unavailable'));
        }
    }

    public function prepareForExport(ConstructionJournalEntry $entry): ConstructionJournalEntry
    {
        if (! $entry->isReportingEntry()) {
            return $entry;
        }
        $display = clone $entry;
        $source = $entry->source_entry_snapshot['source'] ?? [];
        $display->work_description = $entry->work_description."\n".'Источник: '.($source['organization_name'] ?? '—').', журнал №'.($source['journal_number'] ?? '—').', запись №'.($source['entry_number'] ?? '—').' от '.($source['entry_date'] ?? '—').'. Принятые факты без повторного учёта.';
        if (trim((string) $entry->quality_notes) !== '') {
            $display->work_description .= "\n".'Комментарий к отчёту: '.$entry->quality_notes;
        }
        foreach (['weather_conditions', 'problems_description', 'safety_notes', 'visitors_notes', 'quality_notes'] as $field) {
            $display->setAttribute($field, $entry->source_entry_snapshot[$field] ?? null);
        }
        foreach ([
            'workVolumes' => \App\Models\JournalWorkVolume::class,
            'workers' => \App\Models\JournalWorker::class,
            'equipment' => \App\Models\JournalEquipment::class,
            'materials' => \App\Models\JournalMaterial::class,
        ] as $relation => $modelClass) {
            $display->setRelation($relation, collect($entry->source_entry_snapshot[$relation] ?? [])->map(function (array $row) use ($modelClass) {
                $model = new $modelClass;
                $model->forceFill(array_diff_key($row, array_flip(['measurementUnit', 'workType', 'estimateItem', 'material', 'contract_coverage'])));
                foreach (['measurementUnit' => \App\Models\MeasurementUnit::class, 'workType' => \App\Models\WorkType::class, 'estimateItem' => \App\Models\EstimateItem::class, 'material' => \App\Models\Material::class] as $nested => $nestedClass) {
                    if (array_key_exists($nested, $row)) {
                        $model->setRelation($nested, is_array($row[$nested]) ? (new $nestedClass)->forceFill($row[$nested]) : null);
                    }
                }
                return $model;
            }));
        }
        return $display;
    }

    private function assertSnapshot(ConstructionJournalEntry $entry): void
    {
        $volumes = $entry->source_entry_snapshot['workVolumes'] ?? [];
        if (! is_array($volumes) || $volumes === []) {
            throw new DomainException(trans_message('construction_journal.errors.reporting_unavailable'));
        }
        foreach ($volumes as $volume) {
            if (! is_array($volume) || (float) ($volume['quantity'] ?? 0) <= 0 || empty($volume['measurement_unit_id'])) {
                throw new DomainException(trans_message('construction_journal.errors.reporting_unavailable'));
            }
        }
    }

    private function assertEligible(User $actor, ConstructionJournalEntry $source): void
    {
        $source->loadMissing('journal.project');
        if (! $source->journal || ! $source->journal->project) {
            throw new AuthorizationException(trans_message('construction_journal.errors.access_denied'));
        }
        $this->access->assertReadable($actor, $source->journal);
        if ($source->status !== JournalEntryStatusEnum::APPROVED
            || (int) $source->journal->organization_id !== (int) $source->journal->project->organization_id
            || (int) $source->journal->performing_organization_id === (int) $actor->current_organization_id
            || $this->access->approvalOrganizationId($source->journal) !== (int) $actor->current_organization_id
            || ! $this->access->hasPermission($actor, $source->journal->project, ['create', '*'])) {
            throw new DomainException(trans_message('construction_journal.errors.reporting_unavailable'));
        }
    }
}
