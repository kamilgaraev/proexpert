<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

interface RagSourcePrunerInterface
{
    public function pruneForOrganization(int $organizationId, ?int $projectId = null): int;
}
