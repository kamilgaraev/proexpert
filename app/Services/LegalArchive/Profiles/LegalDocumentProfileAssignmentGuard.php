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

    public function assertCompatible(LegalArchiveDocument $document, ?LegalDocumentProfile $current, LegalDocumentProfile $next): void
    {
        if (($current !== null && $current->baseCode !== $next->baseCode)
            || ($current === null && (string) $document->document_type !== $next->category)) {
            throw new DomainException('profile_base_change_not_allowed');
        }

        if (array_diff_key((array) $document->structured_fields, $next->schema, ['obligations' => true]) !== []) {
            throw new DomainException('profile_existing_fields_incompatible');
        }
    }
}
