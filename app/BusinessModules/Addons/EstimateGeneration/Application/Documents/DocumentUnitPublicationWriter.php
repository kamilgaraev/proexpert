<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

interface DocumentUnitPublicationWriter
{
    public function transaction(int $organizationId, int $sessionId, callable $callback): mixed;

    public function write(
        DocumentUnitPublication $publication,
        int $organizationId,
        int $projectId,
        int $sessionId,
        int $documentId,
        int $pageNumber,
        string $sourceVersion,
    ): void;
}
