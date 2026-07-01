<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagSearchResult;
use App\Models\User;

interface AssistantReportSourceRetrieverInterface
{
    /**
     * @param array<string, mixed> $requestContext
     * @return array<int, RagSearchResult>
     */
    public function search(string $query, int $organizationId, User $user, array $requestContext = []): array;
}
