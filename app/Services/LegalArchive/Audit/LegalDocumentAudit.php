<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Audit;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Models\Contract;
use App\Models\User;

interface LegalDocumentAudit
{
    public function record(
        string $event,
        LegalArchiveDocument $document,
        User $actor,
        array $context = [],
    ): void;

    public function recordForActorId(
        string $event,
        LegalArchiveDocument $document,
        ?int $actorId,
        array $context = [],
    ): void;

    public function recordContractForActorId(
        string $event,
        Contract $contract,
        ?int $actorId,
        array $context = [],
    ): void;
}
