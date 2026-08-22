<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\Enums\ConstructionJournal\JournalStatusEnum;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class JournalEntryWorkflowService
{
    public function __construct(
        private readonly ConstructionJournalService $journalService,
        private readonly JournalApprovalService $approvalService,
    ) {}

    public function create(ConstructionJournal $journal, array $data, User $user): ConstructionJournalEntry
    {
        return DB::transaction(function () use ($journal, $data, $user): ConstructionJournalEntry {
            $journal = ConstructionJournal::query()
                ->whereKey($journal->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertJournalActive($journal);

            $idempotencyKey = $this->idempotencyKey($data);
            $payloadFingerprint = $this->payloadFingerprint($data);
            if ($idempotencyKey !== null) {
                $existing = ConstructionJournalEntry::query()
                    ->where('journal_id', $journal->id)
                    ->where('created_by_user_id', $user->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof ConstructionJournalEntry) {
                    if (! hash_equals((string) $existing->payload_fingerprint, $payloadFingerprint)) {
                        throw new DomainException(
                            trans_message('construction_journal.errors.idempotency_conflict')
                        );
                    }

                    return $existing->fresh();
                }
            }

            $entry = $this->journalService->createEntry($journal, [
                ...$data,
                'idempotency_key' => $idempotencyKey,
                'payload_fingerprint' => $idempotencyKey !== null ? $payloadFingerprint : null,
            ], $user);

            if ((bool) ($data['submit_after_create'] ?? false)) {
                $entry = $this->approvalService->submitForApproval($entry, $user);
            }

            return $entry;
        });
    }

    private function assertJournalActive(ConstructionJournal $journal): void
    {
        if ($journal->status !== JournalStatusEnum::ACTIVE) {
            throw new DomainException(trans_message('construction_journal.errors.journal_not_active'));
        }
    }

    private function idempotencyKey(array $data): ?string
    {
        $key = trim((string) ($data['idempotency_key'] ?? ''));

        return $key !== '' ? $key : null;
    }

    private function payloadFingerprint(array $data): string
    {
        unset($data['idempotency_key']);
        $this->sortRecursively($data);

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function sortRecursively(array &$data): void
    {
        foreach ($data as &$value) {
            if (is_array($value)) {
                $this->sortRecursively($value);
            }
        }
        unset($value);

        if (! array_is_list($data)) {
            ksort($data);
        }
    }
}
