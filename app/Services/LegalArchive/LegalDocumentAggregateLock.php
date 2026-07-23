<?php

declare(strict_types=1);

namespace App\Services\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentFile;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use DomainException;
use Illuminate\Database\ConnectionInterface;

final class LegalDocumentAggregateLock
{
    public function lockDocument(
        ConnectionInterface $connection,
        int $organizationId,
        int $documentId,
    ): LegalArchiveDocument {
        if ($connection->transactionLevel() < 1) {
            throw new DomainException('legal_document_lock_requires_transaction');
        }
        if ($connection->getDriverName() === 'pgsql') {
            $connection->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [
                "legal-document:{$organizationId}:{$documentId}",
            ]);
        }
        $document = (new LegalArchiveDocument)->setConnection($connection->getName())->newQuery()
            ->whereKey($documentId)
            ->where('organization_id', $organizationId)
            ->lockForUpdate()
            ->first();
        if (! $document instanceof LegalArchiveDocument) {
            throw new DomainException('legal_workflow_document_not_found');
        }

        return $document;
    }

    public function lockFile(
        ConnectionInterface $connection,
        LegalArchiveDocument $document,
        int $fileId,
    ): LegalArchiveDocumentFile {
        $file = (new LegalArchiveDocumentFile)->setConnection($connection->getName())->newQuery()
            ->whereKey($fileId)
            ->where('organization_id', (int) $document->organization_id)
            ->where('document_id', (int) $document->id)
            ->lockForUpdate()
            ->first();
        if (! $file instanceof LegalArchiveDocumentFile) {
            throw new DomainException('legal_document_file_not_found');
        }

        return $file;
    }

    public function lockVersion(
        ConnectionInterface $connection,
        LegalArchiveDocument $document,
        int $versionId,
    ): LegalArchiveDocumentVersion {
        $versions = (new LegalArchiveDocumentVersion)->setConnection($connection->getName())->newQuery();
        $fileId = $versions->whereKey($versionId)
            ->where('organization_id', (int) $document->organization_id)
            ->where('document_id', (int) $document->id)
            ->value('document_file_id');
        if ($fileId !== null) {
            $this->lockFile($connection, $document, (int) $fileId);
        }
        $version = $versions->whereKey($versionId)
            ->where('organization_id', (int) $document->organization_id)
            ->where('document_id', (int) $document->id)
            ->lockForUpdate()
            ->first();
        if (! $version instanceof LegalArchiveDocumentVersion) {
            throw new DomainException('legal_workflow_version_not_ready');
        }

        return $version;
    }
}
