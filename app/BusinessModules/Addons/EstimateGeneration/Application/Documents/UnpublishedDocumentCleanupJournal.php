<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use Illuminate\Database\ConnectionInterface;
use LogicException;

final readonly class UnpublishedDocumentCleanupJournal
{
    public const TABLE = 'estimate_generation_document_cleanup_debts';

    public function __construct(private ConnectionInterface $database) {}

    /** Must execute after the outer operation rollback, never in its savepoint. */
    public function recordBatch(UnpublishedDocumentObjectBatch $batch): void
    {
        if ($this->database->transactionLevel() !== 0) {
            throw new LogicException('document_cleanup_journal_requires_outer_rollback');
        }
        $this->persist($batch);
    }

    /** Recovery and a known failure commit the failed intent and every cleanup debt atomically. */
    public function recordFailedAdmission(int $id): void
    {
        if ($this->database->transactionLevel() < 1) {
            throw new LogicException('document_admission_cleanup_requires_transaction');
        }
        $row = $this->database->table(DocumentAdmissionOperation::TABLE)->where('id', $id)->where('status', 'failed')->lockForUpdate()->first();
        if ($row === null) {
            throw new LogicException('document_admission_cleanup_intent_missing');
        }
        $session = new \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
        $session->forceFill(['id' => $row->session_id, 'organization_id' => $row->organization_id, 'project_id' => $row->project_id]);
        $this->persist(PreparedDocumentAdmission::batch($session, json_decode($row->objects, true, 512, JSON_THROW_ON_ERROR)));
    }

    private function persist(UnpublishedDocumentObjectBatch $batch): void
    {
        foreach ($batch->objects() as $object) {
            $this->database->table(self::TABLE)->insertOrIgnore(['organization_id' => $object->organizationId,
                'project_id' => $object->projectId, 'session_id' => $object->sessionId, 'storage_path' => $object->path,
                'checksum_sha256' => $object->sha256, 'file_size_bytes' => $object->sizeBytes, 'identity_hash' => $object->fingerprint(),
                'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
