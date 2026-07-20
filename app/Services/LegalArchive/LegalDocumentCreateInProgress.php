<?php

declare(strict_types=1);

namespace App\Services\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use RuntimeException;

final class LegalDocumentCreateInProgress extends RuntimeException
{
    public function __construct(public readonly LegalArchiveDocument $document)
    {
        parent::__construct('legal_document_create_in_progress');
    }
}
