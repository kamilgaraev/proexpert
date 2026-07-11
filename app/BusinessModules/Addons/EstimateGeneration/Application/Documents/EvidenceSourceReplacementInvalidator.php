<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

interface EvidenceSourceReplacementInvalidator
{
    public function invalidateReplacedDocumentSource(
        int $organizationId,
        int $projectId,
        int $sessionId,
        int $documentId,
        string $previousSourceVersion,
    ): int;
}
