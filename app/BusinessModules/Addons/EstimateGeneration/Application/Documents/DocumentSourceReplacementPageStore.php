<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

interface DocumentSourceReplacementPageStore
{
    public function removeStalePages(
        int $organizationId,
        int $projectId,
        int $sessionId,
        int $documentId,
        string $acceptedSourceVersion,
    ): int;
}
