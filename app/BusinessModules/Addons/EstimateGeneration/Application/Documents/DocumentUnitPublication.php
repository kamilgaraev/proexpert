<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ArbitrationDecision;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\CanonicalFactReducer;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;
use InvalidArgumentException;

final readonly class DocumentUnitPublication
{
    /** @var list<ObservationClaim> */
    public array $claims;

    /** @var list<ArbitrationDecision> */
    public array $decisions;

    /** @var list<array{role:string,index:int|null,reason_code:string}> */
    public array $quarantinedItems;

    /** @param list<ObservationClaim> $claims @param list<ArbitrationDecision> $decisions @param list<array{role:string,index:int|null,reason_code:string}> $quarantinedItems */
    public function __construct(
        array $claims,
        array $decisions,
        array $quarantinedItems = [],
    ) {
        if (($claims === []) !== ($decisions === []) || ($claims === [] && $quarantinedItems === [])) {
            throw new InvalidArgumentException('document_unit_publication_empty');
        }
        $this->claims = $claims;
        $this->decisions = $claims === [] ? [] : (new CanonicalFactReducer)->reduce($claims, $decisions);
        $this->quarantinedItems = $quarantinedItems;
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
