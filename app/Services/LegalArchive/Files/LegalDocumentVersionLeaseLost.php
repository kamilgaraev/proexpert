<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Files;

use RuntimeException;

final class LegalDocumentVersionLeaseLost extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('legal_document_version_lease_lost');
    }
}
