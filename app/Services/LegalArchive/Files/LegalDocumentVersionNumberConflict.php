<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Files;

use DomainException;

final class LegalDocumentVersionNumberConflict extends DomainException
{
    public function __construct()
    {
        parent::__construct('legal_document_version_number_taken');
    }
}
