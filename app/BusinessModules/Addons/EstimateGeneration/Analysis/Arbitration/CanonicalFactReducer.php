<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration;

use InvalidArgumentException;

final class CanonicalFactReducer
{
    private const STATUS_PRECEDENCE = [
        'candidate' => 0,
        'accepted' => 1,
        'conditional' => 2,
        'unresolved' => 3,
        'ambiguous' => 4,
        'rejected' => 5,
    ];

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
            $visualKey = $this->visualGroupKey($byId[$decision->claimId]);
            $key = $visualKey ?? (is_array($canonical)
                ? 'canonical|'.$this->matcher->keyForCanonical($canonical)
                : 'claim|'.$decision->claimId);
            $groups[$key][] = $decision;
        }
        $groups = $this->coalesceCanonicalGroups($groups);
        ksort($groups, SORT_STRING);

        $reduced = [];
        foreach ($groups as $key => $group) {
            $group = array_map(fn (ArbitrationDecision $decision): ArbitrationDecision => $this->normalized(
                $decision,
                $byId,
            ), $group);
            usort($group, fn (ArbitrationDecision $left, ArbitrationDecision $right): int => $this->compare(
                $left,
                $right,
                $byId,
            ));
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
                reasonCode: count($group) === 1
                    ? $primary->reasonCode
                    : 'canonical_consensus_'.substr(hash('sha256', $key), 0, 16),
                canonicalClaim: $primary->canonicalClaim,
                reason: $primary->reason,
            );
        }

        return $reduced;
    }

    public function assertReduced(array $claims, array $decisions): void
    {
        if (! hash_equals(
            json_encode($this->reduce($claims, $decisions), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($decisions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        )) {
            throw new InvalidArgumentException('canonical_fact_reduction_required');
        }
    }

    private function visualGroupKey(ObservationClaim $claim): ?string
    {
        $value = $claim->value['data'] ?? null;
        if (! in_array($claim->factType, [
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

    private function coalesceCanonicalGroups(array $groups): array
    {
        $coalesced = [];
        $metadata = [];
        foreach ($groups as $key => $group) {
            if (! str_starts_with($key, 'canonical|')) {
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

        $status = array_key_exists($decision->status, self::STATUS_PRECEDENCE)
            ? $decision->status
            : 'unresolved';
        $reasonCode = $status === $decision->status
            ? $decision->reasonCode
            : 'canonical_arbitration_status_unknown';
        $reason = $status === $decision->status ? $decision->reason : '';

        return new ArbitrationDecision(
            claimId: $decision->claimId,
            status: $status,
            supportingClaimIds: $supportingClaimIds,
            evidenceRefs: $evidenceRefs,
            reasonCode: $reasonCode,
            canonicalClaim: $decision->canonicalClaim,
            reason: $reason,
        );
    }

    /** @param array<string, ObservationClaim> $claims */
    private function compare(ArbitrationDecision $left, ArbitrationDecision $right, array $claims): int
    {
        $status = self::STATUS_PRECEDENCE[$right->status] <=> self::STATUS_PRECEDENCE[$left->status];
        if ($status !== 0) {
            return $status;
        }
        $confidence = $claims[$right->claimId]->confidence <=> $claims[$left->claimId]->confidence;
        if ($confidence !== 0) {
            return $confidence;
        }
        $claim = $left->claimId <=> $right->claimId;
        if ($claim !== 0) {
            return $claim;
        }
        $reason = $left->reasonCode <=> $right->reasonCode;
        if ($reason !== 0) {
            return $reason;
        }

        return $this->canonicalJson($left->canonicalClaim) <=> $this->canonicalJson($right->canonicalClaim);
    }

    private function canonicalJson(?array $value): string
    {
        if ($value === null) {
            return 'null';
        }
        ksort($value, SORT_STRING);

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
