<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Comments;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentComment;
use DomainException;

final class LegalDocumentBlockingCommentGuard
{
    public function hasOpen(LegalArchiveDocument $document, int $versionId): bool
    {
        $comment = (new LegalDocumentComment)->setConnection($document->getConnectionName());

        return $comment->newQuery()
            ->where('organization_id', (int) $document->organization_id)
            ->where('document_id', (int) $document->id)
            ->where('document_version_id', $versionId)
            ->where('is_blocking', true)
            ->where('status', 'open')
            ->exists();
    }

    public function assertNone(LegalArchiveDocument $document, int $versionId): void
    {
        if ($this->hasOpen($document, $versionId)) {
            throw new DomainException('legal_workflow_open_blocking_comments');
        }
    }
}
