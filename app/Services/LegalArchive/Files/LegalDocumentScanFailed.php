<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Files;

use RuntimeException;
use Throwable;

final class LegalDocumentScanFailed extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('legal_document_scan_failed', 0, $previous);
    }
}
