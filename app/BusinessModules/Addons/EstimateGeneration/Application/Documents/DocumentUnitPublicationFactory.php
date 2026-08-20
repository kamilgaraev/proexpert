<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ArbitrationDecision;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ArbitrationInputBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionDocumentInput;

final readonly class DocumentUnitPublicationFactory
{
    /** @param array<string, AiRoleRunResult> $observerResults */
    public function fromAnalysis(
        VisionDocumentInput $source,
        array $observerResults,
        ?AiRoleRunResult $arbitrationResult,
    ): ?DocumentUnitPublication {
        $hasClaims = false;
        foreach ($observerResults as $result) {
            if (is_array($result->payload['claims'] ?? null) && $result->payload['claims'] !== []) {
                $hasClaims = true;
                break;
            }
        }
        if (! $hasClaims) {
            return null;
        }

        $batch = (new ArbitrationInputBuilder)->claimBatch($source, $observerResults);
        $claims = $batch->claims;
        $decisions = $claims === [] ? [] : $this->decisions($claims, $arbitrationResult);

        return new DocumentUnitPublication($claims, $decisions, $batch->quarantined);
    }

    /** @param list<\App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim> $claims @return list<ArbitrationDecision> */
    private function decisions(array $claims, ?AiRoleRunResult $arbitrationResult): array
    {
        $byId = [];
        foreach ($claims as $claim) {
            $byId[$claim->id] = $claim;
        }
        $payload = $arbitrationResult?->payload['decisions'] ?? null;
        if (! is_array($payload)) {
            return $this->candidateDecisions($claims);
        }

        $decisions = [];
        $decidedClaims = [];
        foreach (array_slice($payload, 0, 192) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $claimId = $item['claim_id'] ?? null;
            $status = $item['status'] ?? null;
            $supporting = $item['supporting_claim_ids'] ?? null;
            $evidenceRefs = $item['evidence_refs'] ?? null;
            if (! is_string($claimId) || ! isset($byId[$claimId]) || isset($decidedClaims[$claimId])
                || ! in_array($status, ['accepted', 'candidate', 'unresolved'], true)
                || ! is_array($supporting) || ! is_array($evidenceRefs)
                || array_filter($supporting, 'is_string') !== $supporting
                || array_filter($evidenceRefs, 'is_string') !== $evidenceRefs) {
                continue;
            }
            $supporting = array_values(array_filter(
                array_unique($supporting),
                static fn (string $id): bool => isset($byId[$id]),
            ));
            if ($supporting === []) {
                $supporting = [$claimId];
            }
            $decisions[] = new ArbitrationDecision(
                claimId: $claimId,
                status: $status,
                supportingClaimIds: $supporting,
                evidenceRefs: array_values(array_unique($evidenceRefs)),
                reasonCode: is_string($item['reason_code'] ?? null)
                    ? mb_substr($item['reason_code'], 0, 120)
                    : 'arbiter_decision',
                canonicalClaim: is_array($item['canonical_claim'] ?? null) ? $item['canonical_claim'] : null,
                reason: is_string($item['reason'] ?? null) ? mb_substr($item['reason'], 0, 500) : '',
            );
            $decidedClaims[$claimId] = true;
        }

        foreach ($claims as $claim) {
            if (! isset($decidedClaims[$claim->id])) {
                $decisions[] = $this->candidateDecision($claim);
            }
        }

        return $decisions;
    }

    /** @param list<\App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim> $claims @return list<ArbitrationDecision> */
    private function candidateDecisions(array $claims): array
    {
        return array_map(fn ($claim): ArbitrationDecision => $this->candidateDecision($claim), $claims);
    }

    private function candidateDecision(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim $claim): ArbitrationDecision
    {
        return new ArbitrationDecision(
            claimId: $claim->id,
            status: 'candidate',
            supportingClaimIds: [$claim->id],
            evidenceRefs: $claim->evidenceRef === null ? [] : [$claim->evidenceRef],
            reasonCode: 'independent_observation_preserved',
            canonicalClaim: null,
        );
    }
}
