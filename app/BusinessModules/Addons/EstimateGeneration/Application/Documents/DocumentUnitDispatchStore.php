<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use DateTimeImmutable;

interface DocumentUnitDispatchStore
{
    /** @return list<DocumentUnitDispatchCandidate> */
    public function dueForDocument(int $documentId, string $sourceVersion, DateTimeImmutable $now, int $limit): array;

    /** @return list<DocumentUnitDispatchCandidate> */
    public function dueForRecovery(DateTimeImmutable $now, int $limit): array;

    public function markDispatched(int $unitId, DateTimeImmutable $now, DateTimeImmutable $nextDispatchAt): void;
}
