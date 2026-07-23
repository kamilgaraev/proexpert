<?php

declare(strict_types=1);

namespace App\DTOs\Contract;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Models\Contract;

final readonly class ContractDossierCreationResult
{
    public function __construct(
        public Contract $contract,
        public LegalArchiveDocument $document,
        public bool $replayed,
    ) {}
}
