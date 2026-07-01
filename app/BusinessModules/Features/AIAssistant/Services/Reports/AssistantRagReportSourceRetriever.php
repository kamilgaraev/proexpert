<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagSearchResult;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagRetriever;
use App\Models\User;

final readonly class AssistantRagReportSourceRetriever implements AssistantReportSourceRetrieverInterface
{
    public function __construct(
        private RagRetriever $ragRetriever
    ) {}

    /**
     * @param array<string, mixed> $requestContext
     * @return array<int, RagSearchResult>
     */
    public function search(string $query, int $organizationId, User $user, array $requestContext = []): array
    {
        return $this->ragRetriever->search($query, $organizationId, $user, $requestContext);
    }
}
