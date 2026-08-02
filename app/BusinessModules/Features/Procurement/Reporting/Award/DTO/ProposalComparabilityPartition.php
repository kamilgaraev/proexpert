<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\DTO;

final readonly class ProposalComparabilityPartition
{
    public function __construct(
        public array $comparable,
        public array $excludedReasonByProposalVersionId,
    ) {}
}
