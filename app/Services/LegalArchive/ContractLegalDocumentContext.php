<?php

declare(strict_types=1);

namespace App\Services\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Models\Contract;

final readonly class ContractLegalDocumentContext
{
    public function __construct(
        public Contract $contract,
        public LegalArchiveDocument $document,
        public ?LegalArchiveDocumentVersion $version = null,
    ) {}
}
