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
        foreach ($batch->objects() as $object) {
            $this->database->table(self::TABLE)->insertOrIgnore(['organization_id' => $object->organizationId,
                'project_id' => $object->projectId, 'session_id' => $object->sessionId, 'storage_path' => $object->path,
                'checksum_sha256' => $object->sha256, 'file_size_bytes' => $object->sizeBytes, 'identity_hash' => $object->fingerprint(),
                'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
