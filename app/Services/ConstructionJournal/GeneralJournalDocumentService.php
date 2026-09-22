<?php

declare(strict_types=1);

namespace App\Services\ConstructionJournal;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\ConstructionJournal;
use App\Models\GeneralJournalDocumentVersion;
use App\Models\User;
use App\Services\LegalArchive\CanonicalJson;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Support\Facades\DB;

final class GeneralJournalDocumentService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly UserProjectAccessService $projects,
        private readonly GeneralJournalDocumentInput $input,
    ) {}

    public function prepare(User $user, ConstructionJournal $journal, array $data): GeneralJournalDocumentVersion
    {
        $this->authorize($user, $journal);
        $data = $this->input->validate($data);
        if ($data['document_version_ids'] !== []) {
            $this->authorizeDocuments($user, $journal);
        }
        return DB::transaction(function () use ($user, $journal, $data): GeneralJournalDocumentVersion {
            $journal = ConstructionJournal::query()->with('project')->lockForUpdate()->findOrFail($journal->id);
            $this->authorize($user, $journal);
            $fingerprint = CanonicalJson::fingerprint($data);
            $versions = GeneralJournalDocumentVersion::query()->where('journal_id', $journal->id);
            $existing = (clone $versions)->where('created_by_user_id', $user->id)->where('operation_key', $data['operation_key'])->first();
            if ($existing !== null) {
                if (!hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw new BusinessLogicException(trans_message('construction_journal.errors.idempotency_conflict'), 409);
                }
                return $existing;
            }
            $previous = $versions->orderByDesc('revision')->first();
            if ((int) $data['expected_revision'] !== (int) ($previous?->revision ?? 0)) {
                throw new BusinessLogicException(trans_message('general_journal.stale_revision'), 409);
            }
            $reason = trim((string) ($data['correction_reason'] ?? ''));
            if ($previous !== null && $reason === '') {
                throw new BusinessLogicException(trans_message('general_journal.correction_reason_required'), 422);
            }
            $revision = (int) ($previous?->revision ?? 0) + 1;
            $snapshot = $this->snapshot($journal, $data, $revision, $reason);
            if (strlen(CanonicalJson::encode($snapshot)) > 2097152) {
                throw new BusinessLogicException(trans_message('general_journal.too_large'), 422);
            }
            return GeneralJournalDocumentVersion::query()->create([
                'organization_id' => $journal->organization_id, 'project_id' => $journal->project_id,
                'journal_id' => $journal->id, 'created_by_user_id' => $user->id,
                'revision' => $revision, 'operation_key' => $data['operation_key'],
                'request_fingerprint' => $fingerprint, 'template_version' => GeneralJournalDocumentDefinition::TEMPLATE_VERSION,
                'source_snapshot' => $snapshot, 'snapshot_hash' => CanonicalJson::fingerprint($snapshot),
                'previous_version_id' => $previous?->id, 'correction_reason' => $reason !== '' ? $reason : null,
            ]);
        });
    }

    public function authorize(User $user, ConstructionJournal $journal): void
    {
        if ((int) $journal->organization_id !== (int) $user->current_organization_id
            || !$user->belongsToOrganization((int) $journal->organization_id)
            || $journal->project === null
            || !$this->projects->canAccessProject($user, $journal->project, (int) $journal->organization_id)) {
            throw new BusinessLogicException(trans_message('general_journal.not_found'), 404);
        }
        if (!$this->authorization->can($user, 'construction-journal.export', [
            'organization_id' => (int) $journal->organization_id,
            'project_id' => (int) $journal->project_id, 'strict_project_scope' => true,
        ])) {
            throw new BusinessLogicException(trans_message('general_journal.access_denied'), 403);
        }
    }

    public function authorizeDocuments(User $user, ConstructionJournal $journal): void
    {
        if (!$this->authorization->can($user, 'executive-documentation.view', [
            'organization_id' => (int) $journal->organization_id,
            'project_id' => (int) $journal->project_id, 'strict_project_scope' => true,
        ])) {
            throw new BusinessLogicException(trans_message('general_journal.access_denied'), 403);
        }
    }

    private function snapshot(ConstructionJournal $journal, array $data, int $revision, string $reason): array
    {
        $sections = array_fill_keys(range(1, 6), []);
        foreach ([1, 2, 4, 6] as $number) {
            $sections[$number] = array_values($data['profile']['sections'][$number] ?? []);
        }
        $details = collect($data['work_details'])->keyBy('entry_id');
        $entries = $journal->entries()->where('status', 'approved')->with('materials')->limit(5001)->get();
        if ($entries->count() > 5000) {
            throw new BusinessLogicException(trans_message('general_journal.too_large'), 422);
        }
        if (array_diff($details->keys()->all(), $entries->modelKeys()) !== []) {
            throw new BusinessLogicException(trans_message('general_journal.invalid_entry'), 422);
        }
        $sources = [];
        foreach ($entries as $entry) {
            $detail = $details->get($entry->id, []);
            $works = [(string) $entry->work_description];
            $materialText = $entry->materials->map(static fn ($material): string => trim((string) $material->material_name.' '.$material->quantity.' '.$material->measurement_unit))->implode('; ');
            foreach (['location', 'methods', 'materials', 'tests'] as $key) {
                $value = trim((string) ($detail[$key] ?? ($key === 'materials' ? $materialText : '')));
                if ($value !== '') {
                    $works[] = trans_message('general_journal.'.$key).': '.$value;
                }
            }
            $sections[3][] = [
                'date' => $entry->entry_date->format('d.m.Y'), 'conditions' => (string) ($detail['conditions'] ?? ''),
                'works' => implode("\n", $works),
                'representative' => trim((string) ($detail['representative_position'] ?? '').' '.($detail['representative_name'] ?? '')),
            ];
            $sources[] = ['entry_id' => (int) $entry->id, 'entry_number' => $entry->entry_number, 'approved_at' => $entry->approved_at?->toIso8601String(), 'approved_by_user_id' => $entry->approved_by_user_id];
        }
        $documents = $data['document_version_ids'] === [] ? ['rows' => [], 'sources' => []]
            : app(GeneralJournalSignedDocumentRows::class)->rows($journal, $data['document_version_ids']);
        $sections[5] = $documents['rows'];
        return [
            'template_version' => GeneralJournalDocumentDefinition::TEMPLATE_VERSION,
            'mode' => 'paper_preparation', 'revision' => $revision, 'correction_reason' => $reason !== '' ? $reason : null,
            'journal' => ['number' => $journal->journal_number, 'project_name' => $journal->project->name,
                'project_address' => $journal->project->address, 'start_date' => $journal->start_date?->format('d.m.Y'), 'end_date' => $journal->end_date?->format('d.m.Y')],
            'profile' => array_intersect_key($data['profile'], array_flip(['header', 'representatives', 'title_changes'])),
            'sections' => $sections, 'sources' => ['entries' => $sources, 'documents' => $documents['sources']],
        ];
    }
}
