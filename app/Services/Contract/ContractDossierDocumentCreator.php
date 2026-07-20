<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;

interface ContractDossierDocumentCreator
{
    /** @param array<string, mixed> $data */
    public function create(int $organizationId, ?int $userId, array $data): LegalArchiveDocument;
}
