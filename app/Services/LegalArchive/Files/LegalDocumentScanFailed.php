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

    public function failureCode(): string
    {
        return match ($this->getPrevious()?->getMessage()) {
            'legal_document_malware_detected' => 'malware_detected',
            'legal_document_scanner_unavailable',
            'legal_document_scanner_invalid_response',
            'legal_document_scan_source_unavailable',
            'legal_document_scan_source_unreadable' => 'scanner_unavailable',
            default => 'scan_failed',
        };
    }
}
