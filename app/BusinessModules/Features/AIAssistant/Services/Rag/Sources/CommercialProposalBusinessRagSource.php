<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

final class CommercialProposalBusinessRagSource extends SalesBusinessRagSource
{
    public function sourceType(): string { return 'commercial_proposals_business'; }
}
