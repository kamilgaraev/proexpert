<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Services;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\Models\CompletedWork;
use App\Models\ConstructionJournalEntry;
use App\Models\JournalWorkVolume;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

final class HiddenWorkActAutofillService
{
    public function __construct(
        private readonly ExecutiveDocumentNumberGenerator $numberGenerator,
    ) {
    }

    public function forJournalEntryReference(ConstructionJournalEntry $entry, int $organizationId): array
    {
        $entry->loadMissing([
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

        return $this->buildDefaults(
            $organizationId,
            new EloquentCollection([$entry]),
            $entry->completedWorks,
            collect()
        );
    }

    public function forCompletedWorkReference(CompletedWork $work, int $organizationId): array
    {
        $work->loadMissing([
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

        $entries = $work->journalEntry
            ? new EloquentCollection([$work->journalEntry])
            : new EloquentCollection();

        return $this->buildDefaults(
            $organizationId,
            $entries,
            new EloquentCollection([$work]),
            collect()
        );
    }

    public function applyToDocumentPayload(array $validated, ExecutiveDocumentSet $set): array
    {
        if (($validated['document_type'] ?? null) !== 'hidden_work_act') {
            return $validated;
        }

        $completedWork = $this->resolveCompletedWork($validated, $set);
        $entryIds = $this->collectJournalEntryIds($validated, $completedWork);
        $entries = $this->resolveJournalEntries($entryIds, $set);

        if ($entries->isEmpty() && $completedWork?->journalEntry !== null) {
            $entries = new EloquentCollection([$completedWork->journalEntry]);
        }

        if (empty($validated['journal_entry_id']) && $entries->isNotEmpty()) {
            $validated['journal_entry_id'] = (int) $entries->first()->id;
        }

        $relatedDocuments = $this->resolveRelatedDocuments($validated, $set);
        $works = $completedWork ? new EloquentCollection([$completedWork]) : $entries->pluck('completedWorks')->flatten();
        $defaults = $this->buildDefaults($set->organization_id, $entries, $works, $relatedDocuments);

        $profileData = is_array($validated['profile_data'] ?? null) ? $validated['profile_data'] : [];
        $previewActNumber = data_get($validated, 'metadata.hidden_work_act_autofill.generated_act_number');

        if (
            is_string($previewActNumber)
            && ($profileData['act_number'] ?? null) === $previewActNumber
        ) {
            unset($profileData['act_number']);
        }

        $validated['profile_data'] = $this->mergeProfileData($profileData, $defaults['profile_data']);

        if (empty($validated['document_date']) && !empty($defaults['document_date'])) {
            $validated['document_date'] = $defaults['document_date'];
        }

        if (empty($validated['completed_work_id']) && !empty($defaults['completed_work_id'])) {
            $validated['completed_work_id'] = $defaults['completed_work_id'];
        }

        $validated['relations'] = $this->mergeJournalRelations($validated['relations'] ?? [], $entries);
        $validated['metadata'] = array_merge(
            is_array($validated['metadata'] ?? null) ? $validated['metadata'] : [],
            $defaults['metadata']
        );

        return $validated;
    }

    private function buildDefaults(
        int $organizationId,
        EloquentCollection $entries,
        EloquentCollection|Collection $works,
        Collection $relatedDocuments
    ): array {
        $entries = $entries->filter()->unique('id')->values();
        $works = $works->filter()->unique('id')->values();
        $dateCandidates = collect();

        foreach ($entries as $entry) {
            $this->pushDate($dateCandidates, $entry->entry_date);
        }

        foreach ($works as $work) {
            $this->pushDate($dateCandidates, $work->completion_date);
        }

        $startedAt = $dateCandidates->sort()->first();
        $finishedAt = $dateCandidates->sortDesc()->first();
        $documentDateCandidates = collect([$finishedAt]);

        foreach ($relatedDocuments as $document) {
            $this->pushDate($documentDateCandidates, $document->document_date);
        }

        $documentDate = $documentDateCandidates->filter()->sortDesc()->first();
        $presentedWorks = $this->presentedWorks($entries, $works);
        $actualVolume = $this->actualVolume($entries, $works);
        $materials = $this->materialsSummary($entries);
        $firstEntry = $entries->first();
        $firstWork = $works->first();
        $titleWork = $firstEntry?->work_description ?? $firstWork?->workType?->name ?? $firstWork?->notes;
        $actNumber = $this->numberGenerator->generateDocumentNumber($organizationId, 'hidden_work_act');

        return [
            'document_date' => $documentDate,
            'completed_work_id' => $firstWork?->id,
            'title' => $titleWork ? 'Акт скрытых работ: ' . $titleWork : null,
            'profile_data' => array_filter([
                'act_number' => $actNumber,
                'presented_works' => $presentedWorks,
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
                'actual_volume' => $actualVolume,
                'materials_summary' => $materials,
                'next_works_permission' => 'Последующие работы разрешаются после приемки указанных скрытых работ.',
                'journal_entry_id' => $firstEntry?->id,
                'journal_entry_number' => $firstEntry?->entry_number,
                'journal_entry_date' => $firstEntry?->entry_date?->format('Y-m-d'),
                'journal_name' => $firstEntry?->journal?->name,
                'journal_number' => $firstEntry?->journal?->journal_number,
                'work_description' => $firstEntry?->work_description,
            ], static fn ($value): bool => $value !== null && $value !== ''),
            'metadata' => [
                'hidden_work_act_autofill' => [
                    'source' => 'construction_journal',
                    'generated_act_number' => $actNumber,
                    'journal_entry_ids' => $entries->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all(),
                    'completed_work_ids' => $works->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all(),
                    'related_document_ids' => $relatedDocuments->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all(),
                    'date_basis' => [
                        'started_at' => $startedAt,
                        'finished_at' => $finishedAt,
                        'document_date' => $documentDate,
                    ],
                ],
            ],
        ];
    }

    private function resolveCompletedWork(array $validated, ExecutiveDocumentSet $set): ?CompletedWork
    {
        $completedWorkId = (int) ($validated['completed_work_id'] ?? 0);

        if ($completedWorkId <= 0) {
            return null;
        }

        return CompletedWork::query()
            ->where('organization_id', $set->organization_id)
            ->where('project_id', $set->project_id)
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
            ])
            ->find($completedWorkId);
    }

    private function collectJournalEntryIds(array $validated, ?CompletedWork $completedWork): array
    {
        $ids = collect([$validated['journal_entry_id'] ?? null]);

        foreach (($validated['relations'] ?? []) as $relation) {
            if (
                ($relation['relation_type'] ?? null) === 'journal_entry'
                && ($relation['target_type'] ?? null) === 'journal_entry'
            ) {
                $ids->push($relation['target_id'] ?? null);
            }
        }

        if ($completedWork?->journal_entry_id) {
            $ids->push($completedWork->journal_entry_id);
        }

        return $ids
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function resolveJournalEntries(array $entryIds, ExecutiveDocumentSet $set): EloquentCollection
    {
        if ($entryIds === []) {
            return new EloquentCollection();
        }

        return ConstructionJournalEntry::query()
            ->whereIn('id', $entryIds)
            ->whereHas('journal', static fn ($query) => $query
                ->where('organization_id', $set->organization_id)
                ->where('project_id', $set->project_id))
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
            ])
            ->orderBy('entry_date')
            ->orderBy('entry_number')
            ->get();
    }

    private function resolveRelatedDocuments(array $validated, ExecutiveDocumentSet $set): Collection
    {
        $documentIds = collect($validated['relations'] ?? [])
            ->filter(static fn (array $relation): bool => !in_array((string) ($relation['target_type'] ?? ''), [
                'journal_entry',
                'material',
                'supplier',
            ], true))
            ->pluck('target_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($documentIds === []) {
            return collect();
        }

        return ExecutiveDocument::query()
            ->where('organization_id', $set->organization_id)
            ->where('project_id', $set->project_id)
            ->whereIn('id', $documentIds)
            ->get(['id', 'document_date']);
    }

    private function mergeProfileData(array $profileData, array $defaults): array
    {
        foreach ($defaults as $key => $value) {
            if (($profileData[$key] ?? null) === null || $profileData[$key] === '' || $profileData[$key] === []) {
                $profileData[$key] = $value;
            }
        }

        return $profileData;
    }

    private function mergeJournalRelations(array $relations, EloquentCollection $entries): array
    {
        $existing = collect($relations)
            ->map(static fn (array $relation): string => implode(':', [
                (string) ($relation['relation_type'] ?? ''),
                (string) ($relation['target_type'] ?? ''),
                (string) ($relation['target_id'] ?? ''),
            ]))
            ->all();

        foreach ($entries as $entry) {
            $key = 'journal_entry:journal_entry:' . $entry->id;

            if (in_array($key, $existing, true)) {
                continue;
            }

            $relations[] = [
                'relation_type' => 'journal_entry',
                'target_type' => 'journal_entry',
                'target_id' => (int) $entry->id,
                'label' => trim(sprintf(
                    '%s · %s',
                    $entry->entry_number ?: $entry->id,
                    (string) $entry->work_description
                )),
            ];
        }

        return $relations;
    }

    private function presentedWorks(EloquentCollection $entries, EloquentCollection|Collection $works): ?string
    {
        $items = $entries
            ->pluck('work_description')
            ->merge($works->map(static fn (CompletedWork $work): ?string => $work->workType?->name ?? $work->notes))
            ->filter()
            ->unique()
            ->values();

        return $items->isEmpty() ? null : $items->implode("\n");
    }

    private function actualVolume(EloquentCollection $entries, EloquentCollection|Collection $works): ?string
    {
        $volumes = collect();

        foreach ($works as $work) {
            if ($work->journalWorkVolume) {
                $volumes->push($this->formatWorkVolume($work->journalWorkVolume));
                continue;
            }

            if ($work->quantity !== null) {
                $volumes->push($this->formatQuantity((float) $work->quantity, null, $work->workType?->name));
            }
        }

        if ($volumes->isEmpty()) {
            foreach ($entries as $entry) {
                foreach ($entry->workVolumes as $volume) {
                    $volumes->push($this->formatWorkVolume($volume));
                }
            }
        }

        $volumes = $volumes->filter()->unique()->values();

        return $volumes->isEmpty() ? null : $volumes->implode('; ');
    }

    private function materialsSummary(EloquentCollection $entries): ?string
    {
        $materials = collect();

        foreach ($entries as $entry) {
            foreach ($entry->materials as $material) {
                $materials->push($this->formatQuantity(
                    (float) $material->quantity,
                    $material->measurement_unit,
                    $material->material_name
                ));
            }
        }

        $materials = $materials->filter()->unique()->values();

        return $materials->isEmpty() ? null : $materials->implode('; ');
    }

    private function formatWorkVolume(JournalWorkVolume $volume): string
    {
        return $this->formatQuantity(
            (float) $volume->quantity,
            $volume->measurementUnit?->short_name ?? $volume->measurementUnit?->name,
            $volume->workType?->name ?? $volume->estimateItem?->name
        );
    }

    private function formatQuantity(float $quantity, ?string $unit, ?string $name = null): string
    {
        $parts = [];

        if ($name) {
            $parts[] = trim($name) . ':';
        }

        $parts[] = number_format($quantity, 3, '.', '');

        if ($unit) {
            $parts[] = trim($unit);
        }

        return trim(implode(' ', $parts));
    }

    private function pushDate(Collection $dates, mixed $value): void
    {
        if ($value instanceof CarbonInterface) {
            $dates->push($value->format('Y-m-d'));
            return;
        }

        if (is_string($value) && $value !== '') {
            $dates->push(substr($value, 0, 10));
        }
    }
}
