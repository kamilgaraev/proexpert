<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Editor;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use DomainException;
use Illuminate\Database\ConnectionInterface;

final readonly class LegalDocumentEditorAvailability
{
    public function __construct(private ConnectionInterface $connection) {}

    public function currentVersionEditable(LegalArchiveDocument $document): bool
    {
        $version = $document->currentVersion;
        if (! $version instanceof LegalArchiveDocumentVersion) {
            return false;
        }

        try {
            (new LegalDocumentEditGuard($this->connection))->assertEditorOpenAllowed($document, $version);

            return true;
        } catch (DomainException) {
            return false;
        }
    }
}
