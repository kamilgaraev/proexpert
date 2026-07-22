<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Profiles;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use DomainException;

final class LegalDocumentProfileAssignmentGuard
{
    public function canAssign(LegalArchiveDocument $document): bool
    {
        return (string) $document->lifecycle_status === 'draft'
            && (string) $document->approval_status !== 'approved';
    }

    public function assertCanAssign(LegalArchiveDocument $document): void
    {
        if (! $this->canAssign($document)) {
            throw new DomainException('profile_correction_not_allowed');
        }
    }
}
