<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding;

final readonly class DocumentSheetOperationScope
{
    public function __construct(public int $organizationId, public int $projectId, public int $sessionId, public int $documentId, public int $unitId, public string $sourceVersion, public string $claimToken) {}

    /** @return array<string, int|string> */
    public function attributes(): array
    {
        return ['organization_id' => $this->organizationId, 'project_id' => $this->projectId, 'session_id' => $this->sessionId, 'document_id' => $this->documentId, 'unit_id' => $this->unitId, 'source_version' => $this->sourceVersion];
    }
}
