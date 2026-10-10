<?php

declare(strict_types=1);

namespace App\Services\ConstructionJournal;

use App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService;
use App\Exceptions\BusinessLogicException;
use App\Jobs\ConstructionJournal\GenerateJournalExportJob;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\GeneralJournalDocumentVersion;
use App\Models\JournalExport;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;

final class JournalExportWorkflowService
{
    public function __construct(private readonly OfficialFormsExportService $files) {}

    public function request(
        User $user,
        ConstructionJournal $journal,
        string $type,
        string $format,
        array $options,
        string $idempotencyKey,
        ?ConstructionJournalEntry $entry = null,
    ): JournalExport {
        app(ConstructionJournalAccessService::class)->assertReadable($user, $journal, ['export']);
        if ($entry && (int) $entry->journal_id !== (int) $journal->id) {
            throw new DomainException(trans_message('construction_journal.errors.access_denied'));
        }
        if (! empty($options['estimate_id']) && ! \App\Models\Estimate::query()
            ->whereKey($options['estimate_id'])->where('organization_id', $journal->organization_id)
            ->where('project_id', $journal->project_id)->where('status', 'approved')->exists()) {
            throw new DomainException(trans_message('construction_journal.errors.invalid_estimate'));
        }
        if (! empty($options['document_version_id']) && ! \App\Models\GeneralJournalDocumentVersion::query()
            ->whereKey($options['document_version_id'])->where('organization_id', $journal->organization_id)
            ->where('project_id', $journal->project_id)->where('journal_id', $journal->id)->exists()) {
            throw new DomainException(trans_message('construction_journal.errors.access_denied'));
        }
        ksort($options);
        $fingerprint = hash('sha256', json_encode([
            'journal_id' => $journal->id,
            'entry_id' => $entry?->id,
            'type' => $type,
            'format' => $format,
            'options' => $options,
        ], JSON_THROW_ON_ERROR));

        $export = JournalExport::query()->firstOrCreate([
            'organization_id' => $journal->organization_id,
            'requested_by_user_id' => $user->id,
            'idempotency_key' => $idempotencyKey,
        ], [
            'project_id' => $journal->project_id,
            'journal_id' => $journal->id,
            'entry_id' => $entry?->id,
            'type' => $type,
            'format' => $format,
            'options' => $options,
            'request_fingerprint' => $fingerprint,
            'status' => JournalExport::STATUS_QUEUED,
            'progress' => 0,
        ]);

        if (! hash_equals($export->request_fingerprint, $fingerprint)) {
            throw new DomainException(trans_message('construction_journal.errors.idempotency_conflict'));
        }

        if ($export->wasRecentlyCreated) {
            GenerateJournalExportJob::dispatch($export->id)->afterCommit();
        }

        return $export;
    }

    public function payload(JournalExport $export, User $user): array
    {
        $journal = ConstructionJournal::query()->find($export->journal_id);
        if (! $journal || (int) $export->organization_id !== (int) $journal->organization_id
            || (int) $export->project_id !== (int) $journal->project_id) {
            throw new DomainException(trans_message('construction_journal.errors.export_not_found'));
        }
        app(ConstructionJournalAccessService::class)->assertReadable($user, $journal);
        if ($export->type === 'general') {
            $version = GeneralJournalDocumentVersion::query()
                ->where('organization_id', $journal->organization_id)
                ->where('project_id', $journal->project_id)->where('journal_id', $journal->id)
                ->find($export->options['document_version_id'] ?? 0);
            if (! $version) {
                throw new DomainException(trans_message('construction_journal.errors.export_not_found'));
            }
            if (($version->source_snapshot['sources']['documents'] ?? []) !== []) {
                try {
                    app(GeneralJournalDocumentService::class)->authorizeDocuments($user, $journal);
                } catch (BusinessLogicException $exception) {
                    if ($exception->getCode() !== 403) {
                        throw $exception;
                    }
                    throw new AuthorizationException($exception->getMessage());
                }
            }
        }

        $payload = [
            'id' => $export->id,
            'status' => $export->status,
            'progress' => $export->progress,
            'type' => $export->type,
            'format' => $export->format,
            'error_code' => $export->error_code,
        ];

        if ($export->status === JournalExport::STATUS_COMPLETED && $export->result_path) {
            $payload['filename'] = basename($export->result_path);
            $payload['url'] = $this->files->getFileService()->temporaryUrl($export->result_path, 15);
            $payload['expires_at'] = now()->addMinutes(15)->toIso8601String();
        }

        return $payload;
    }
}
