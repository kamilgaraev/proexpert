<?php

declare(strict_types=1);

namespace App\Services\ConstructionJournal;

use App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService;
use App\Jobs\ConstructionJournal\GenerateJournalExportJob;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\JournalExport;
use App\Models\User;
use DomainException;

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
        if ((int) $export->organization_id !== (int) $user->current_organization_id
            || (int) $export->requested_by_user_id !== (int) $user->id) {
            throw new DomainException(trans_message('construction_journal.errors.export_not_found'));
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
