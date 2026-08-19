<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration;

use InvalidArgumentException;

final class CanonicalFactReducer
{
    public function __construct(private readonly ClaimSemanticMatcher $matcher = new ClaimSemanticMatcher) {}

    public function reduce(array $claims, array $decisions): array
    {
        $byId = [];
        foreach ($claims as $key => $claim) {
            if (! $claim instanceof ObservationClaim) {
                throw new InvalidArgumentException('canonical_fact_claim_invalid');
            }
            $byId[is_string($key) ? $key : $claim->id] = $claim;
        }

        $groups = [];
        foreach ($decisions as $decision) {
            if (! $decision instanceof ArbitrationDecision || ! isset($byId[$decision->claimId])) {
                throw new InvalidArgumentException('canonical_fact_decision_invalid');
            }
            $canonical = $decision->canonicalClaim;
            $visualKey = $this->visualGroupKey($byId[$decision->claimId], $decision->status);
            $key = $visualKey ?? ($decision->status === 'accepted' && is_array($canonical)
                ? 'accepted|'.$this->matcher->keyForCanonical($canonical)
                : 'claim|'.$decision->claimId.'|'.$decision->status);
            $groups[$key][] = $decision;
        }
        $groups = $this->coalesceAcceptedGroups($groups);

        $reduced = [];
        foreach ($groups as $key => $group) {
            if (! $this->isMergeableGroup($key) || count($group) === 1) {
                foreach ($group as $decision) {
                    $reduced[] = $this->normalized($decision, $byId);
                }

                continue;
            }
            usort($group, function (ArbitrationDecision $left, ArbitrationDecision $right) use ($byId, $key): int {
                if (str_starts_with($key, 'visual|')) {
                    $status = ($right->status === 'accepted') <=> ($left->status === 'accepted');
                    if ($status !== 0) {
                        return $status;
                    }
                }
                $confidence = $byId[$right->claimId]->confidence <=> $byId[$left->claimId]->confidence;

                return $confidence !== 0 ? $confidence : $left->claimId <=> $right->claimId;
            });
            $primary = $group[0];
            $support = [];
            $evidence = [];
            foreach ($group as $decision) {
                foreach ([$decision->claimId, ...$decision->supportingClaimIds] as $claimId) {
                    if (isset($byId[$claimId])) {
                        $support[$claimId] = true;
                        $claimEvidence = $byId[$claimId]->evidenceRef;
                        if ($claimEvidence !== null) {
                            $evidence[$claimEvidence] = true;
                        }
                    }
                }
                foreach ($decision->evidenceRefs as $evidenceRef) {
                    $evidence[$evidenceRef] = true;
                }
            }
            $supportingClaimIds = array_keys($support);
            $evidenceRefs = array_keys($evidence);
            sort($supportingClaimIds, SORT_STRING);
            sort($evidenceRefs, SORT_STRING);
            $reduced[] = new ArbitrationDecision(
                claimId: $primary->claimId,
                status: $primary->status,
                supportingClaimIds: $supportingClaimIds,
                evidenceRefs: $evidenceRefs,
                reasonCode: 'canonical_consensus_'.substr(hash('sha256', $key), 0, 16),
                canonicalClaim: $primary->canonicalClaim,
                reason: $primary->reason,
            );
        }

        return $reduced;
    }

    private function visualGroupKey(ObservationClaim $claim, string $status): ?string
    {
        $value = $claim->value['data'] ?? null;
        if (! in_array($status, ['accepted', 'candidate'], true)
            || ! in_array($claim->factType, [
                'sanitary_fixture', 'kitchen_fixture', 'furniture', 'unknown_fixture',
            ], true)
            || ! is_string($value) || trim($value) === '') {
            return null;
        }

        return 'visual|'.(new VisualObjectIdentity)->identity(
            $claim->factType,
            $claim->entityKey,
            $value,
        );
    }

    private function isMergeableGroup(string $key): bool
    {
        return str_starts_with($key, 'accepted|') || str_starts_with($key, 'visual|');
    }

    private function coalesceAcceptedGroups(array $groups): array
    {
        $coalesced = [];
        $metadata = [];
        foreach ($groups as $key => $group) {
            if (! str_starts_with($key, 'accepted|')) {
                $coalesced[$key] = $group;

                continue;
            }
            $canonical = $group[0]->canonicalClaim;
            if (! is_array($canonical)) {
                $coalesced[$key] = $group;

                continue;
            }
            $signature = $this->matcher->factSignatureForCanonical($canonical);
            $support = $this->supportSet($group);
            $target = null;
            foreach ($metadata as $candidateKey => $candidate) {
                if ($candidate['signature'] === $signature
                    && array_intersect_key($candidate['support'], $support) !== []) {
                    $target = $candidateKey;
                    break;
                }
            }
            if ($target === null) {
                $coalesced[$key] = $group;
                $metadata[$key] = ['signature' => $signature, 'support' => $support];

                continue;
            }
            $coalesced[$target] = [...$coalesced[$target], ...$group];
            $metadata[$target]['support'] += $support;
        }

        return $coalesced;
    }

    private function supportSet(array $decisions): array
    {
        $support = [];
        foreach ($decisions as $decision) {
            foreach ([$decision->claimId, ...$decision->supportingClaimIds] as $claimId) {
                $support[$claimId] = true;
            }
        }

        return $support;
    }

    private function normalized(ArbitrationDecision $decision, array $claims): ArbitrationDecision
    {
        $support = [];
        $evidence = [];
        foreach ([$decision->claimId, ...$decision->supportingClaimIds] as $claimId) {
            if (! isset($claims[$claimId])) {
                continue;
            }
            $support[$claimId] = true;
            if ($claims[$claimId]->evidenceRef !== null) {
                $evidence[$claims[$claimId]->evidenceRef] = true;
            }
        }
        foreach ($decision->evidenceRefs as $evidenceRef) {
            $evidence[$evidenceRef] = true;
        }
        $supportingClaimIds = array_keys($support);
        $evidenceRefs = array_keys($evidence);
        sort($supportingClaimIds, SORT_STRING);
        sort($evidenceRefs, SORT_STRING);

        return new ArbitrationDecision(
            claimId: $decision->claimId,
            status: $decision->status,
            supportingClaimIds: $supportingClaimIds,
            evidenceRefs: $evidenceRefs,
            reasonCode: $decision->reasonCode,
            canonicalClaim: $decision->canonicalClaim,
            reason: $decision->reason,
        );
    }
}
