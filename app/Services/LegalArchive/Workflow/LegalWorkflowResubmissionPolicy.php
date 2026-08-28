<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowInstance;

final class LegalWorkflowResubmissionPolicy
{
    private const STATUSES_REQUIRING_NEW_VERSION = ['approved', 'rejected', 'returned'];

    public static function requiresNewVersion(
        ?LegalWorkflowInstance $latest,
        ?LegalArchiveDocumentVersion $version,
    ): bool {
        return $latest instanceof LegalWorkflowInstance
            && $version instanceof LegalArchiveDocumentVersion
            && in_array($latest->status, self::STATUSES_REQUIRING_NEW_VERSION, true)
            && (int) $latest->document_version_id === (int) $version->id
            && hash_equals((string) $latest->document_content_hash, (string) $version->content_hash);
    }
}
