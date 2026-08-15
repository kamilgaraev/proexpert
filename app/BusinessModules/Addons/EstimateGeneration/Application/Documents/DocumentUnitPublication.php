<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ArbitrationDecision;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;
use InvalidArgumentException;

final readonly class DocumentUnitPublication
{
    /** @param list<ObservationClaim> $claims @param list<ArbitrationDecision> $decisions @param list<array{role:string,index:int|null,reason_code:string}> $quarantinedItems */
    public function __construct(
        public array $claims,
        public array $decisions,
        public array $quarantinedItems = [],
    ) {
        if (($claims === []) !== ($decisions === []) || ($claims === [] && $quarantinedItems === [])) {
            throw new InvalidArgumentException('document_unit_publication_empty');
        }
    }

    public function assertScope(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
    ): void {
        foreach ($this->claims as $claim) {
            if ($claim->organizationId !== $organizationId
                || $claim->projectId !== $projectId
                || $claim->sessionId !== $sessionId
                || $claim->sourceVersion !== $sourceVersion) {
                throw new InvalidArgumentException('document_unit_publication_scope_invalid');
            }
        }
    }
}
