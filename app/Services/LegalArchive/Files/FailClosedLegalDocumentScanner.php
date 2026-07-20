<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Files;

use Illuminate\Http\UploadedFile;
use RuntimeException;

final class FailClosedLegalDocumentScanner implements LegalDocumentScanner
{
    public function assertClean(UploadedFile $upload): void
    {
        throw new RuntimeException('legal_document_scanner_unavailable');
    }
}
