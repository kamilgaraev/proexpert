<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Files;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use RuntimeException;
use Throwable;

final class LegalDocumentScanFailed extends RuntimeException
{
    public function __construct(
        public readonly LegalArchiveDocumentVersion $version,
        Throwable $previous,
    ) {
        parent::__construct('legal_document_scan_failed', 0, $previous);
    }
}
