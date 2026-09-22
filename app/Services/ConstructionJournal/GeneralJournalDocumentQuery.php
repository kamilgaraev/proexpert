<?php

declare(strict_types=1);

namespace App\Services\ConstructionJournal;

use App\Models\ConstructionJournal;
use App\Models\GeneralJournalDocumentVersion;
use App\Models\User;

final class GeneralJournalDocumentQuery
{
    public function __construct(private readonly GeneralJournalDocumentService $documents) {}

    public function read(User $user, ConstructionJournal $journal, ?int $versionId = null): array
    {
        $this->documents->authorize($user, $journal);
        $versions = GeneralJournalDocumentVersion::query()->where('organization_id', $journal->organization_id)
            ->where('project_id', $journal->project_id)->where('journal_id', $journal->id);
        $selected = $versionId !== null ? (clone $versions)->findOrFail($versionId) : (clone $versions)->orderByDesc('revision')->first();
        if ($selected !== null && ($selected->source_snapshot['sources']['documents'] ?? []) !== []) {
            $this->documents->authorizeDocuments($user, $journal);
        }
        return [
            'mode' => 'paper_preparation', 'template_version' => GeneralJournalDocumentDefinition::TEMPLATE_VERSION,
            'header_fields' => GeneralJournalDocumentDefinition::headerFields(),
            'representative_groups' => GeneralJournalDocumentDefinition::representativeGroups(),
            'sections' => GeneralJournalDocumentDefinition::sections(),
            'version' => $selected === null ? null : $this->payload($selected),
            'history' => $versions->orderByDesc('revision')->limit(50)->get(['id', 'revision', 'previous_version_id', 'created_at', 'created_by_user_id', 'correction_reason'])->toArray(),
        ];
    }

    public function payload(GeneralJournalDocumentVersion $version): array
    {
        return [
            'id' => $version->id, 'revision' => $version->revision, 'previous_version_id' => $version->previous_version_id,
            'template_version' => $version->template_version, 'snapshot' => $version->source_snapshot,
            'snapshot_hash' => $version->snapshot_hash, 'created_at' => $version->created_at?->toIso8601String(),
            'created_by_user_id' => $version->created_by_user_id, 'correction_reason' => $version->correction_reason,
        ];
    }
}
