<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\VisualObjectIdentity;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\VisualObjectScopePolicy;

final class VisualInventoryProjector
{
    private const FACT_TYPES = [
        'sanitary_fixture',
        'kitchen_fixture',
        'equipment',
        'furniture',
        'unknown_fixture',
    ];

    public function __construct(
        private readonly VisualObjectIdentity $identity = new VisualObjectIdentity,
        private readonly VisualObjectScopePolicy $scopePolicy = new VisualObjectScopePolicy,
        private readonly VisualQuantityParser $quantityParser = new VisualQuantityParser,
        private readonly VisualArbitrationPolicy $arbitrationPolicy = new VisualArbitrationPolicy,
    ) {}

    /**
     * @param  array<string, array<string, mixed>>  $observers
     * @param  array<string, mixed>|null  $arbitration
     * @param  array{document_id:int,page_id:int,page_number:int,source_version:string}  $scope
     * @return array{items:list<array<string,mixed>>,quarantined_items:list<array<string,mixed>>}
     */
    public function project(array $observers, ?array $arbitration, array $scope): array
    {
        $decisions = $this->decisions($arbitration);
        $conditional = $this->hasConditionalNote($observers, $scope);
        $groups = [];
        $quarantined = [];

        foreach ($observers as $role => $observer) {
            if (! is_string($role) || ! is_array($observer) || ! $this->sameScope($observer['source'] ?? null, $scope)) {
                $quarantined[] = ['section' => 'visual_inventory', 'reason_code' => 'observer_scope_invalid'];

                continue;
            }
            $shortRole = str_replace('observer_', '', $role);
            $evidence = $this->evidence($observer['evidence'] ?? null, $scope);
            foreach (is_array($observer['claims'] ?? null) ? $observer['claims'] : [] as $index => $claim) {
                if (! is_array($claim)
                    || ! is_string($claim['entityKey'] ?? null)
                    || ! is_string($claim['factType'] ?? null)
                    || ! in_array($claim['factType'], self::FACT_TYPES, true)) {
                    if (! is_array($claim) || ! is_string($claim['factType'] ?? null)) {
                        $quarantined[] = ['section' => 'visual_inventory', 'index' => is_int($index) ? $index : null, 'reason_code' => 'visual_object_invalid'];
                    }

                    continue;
                }
                $value = $claim['value']['data'] ?? null;
                $evidenceRef = $claim['evidenceRef'] ?? null;
                if (! is_string($value) || trim($value) === '' || ! is_string($evidenceRef) || ! isset($evidence[$evidenceRef])) {
                    $quarantined[] = ['section' => 'visual_inventory', 'index' => is_int($index) ? $index : null, 'reason_code' => 'visual_object_evidence_invalid'];

                    continue;
                }
                $claimId = $shortRole.':'.((int) $index + 1);
                $decision = $decisions[$claimId] ?? null;
                $category = $claim['factType'];
                $classification = $this->identity->classification($value, $claim['entityKey'], $category);
                $objectType = $classification->objectType;
                if ($classification->limitationCode !== null) {
                    $category = 'unknown_fixture';
                }
                $scopeValue = $classification->limitationCode !== null
                    ? 'requires_confirmation'
                    : $this->estimateScope($category, $value, $conditional);
                $quantity = $this->quantityParser->parse($value, $claim['entityKey'], $objectType);
                $normalizedDecision = $this->arbitrationPolicy->normalize($decision ?? [
                    'status' => 'conditional',
                    'reason_code' => 'minority_evidence_preserved',
                ]);
                $candidate = [
                    'source_key' => mb_substr($claim['entityKey'], 0, 120),
                    'source_label' => mb_substr(trim($value), 0, 160),
                    'category' => $category,
                    'object_type' => $objectType,
                    'quantity_result' => $quantity,
                    'room_key' => $this->identity->roomKey($claim['entityKey']),
                    'scope' => $scopeValue,
                    'classification_limitation' => $classification->limitationCode,
                    'evidence_locator' => $evidence[$evidenceRef],
                    'arbitration' => $this->arbitrationPresentation($normalizedDecision),
                    'claim_id' => $claimId,
                    'supporting_claim_ids' => [
                        $claimId,
                        ...array_values(array_filter(
                            is_array($decision['supporting_claim_ids'] ?? null) ? $decision['supporting_claim_ids'] : [],
                            'is_string',
                        )),
                    ],
                    'evidence_refs' => [
                        $shortRole.':'.$evidenceRef,
                        ...array_values(array_filter(
                            is_array($decision['evidence_refs'] ?? null) ? $decision['evidence_refs'] : [],
                            'is_string',
                        )),
                    ],
                ];
                $identity = $this->identity->identity($category, $claim['entityKey'], $value);
                $groups[$identity][] = $candidate;
            }
        }

        ksort($groups, SORT_STRING);
        $items = [];
        foreach ($groups as $identity => $candidates) {
            $items[] = $this->reduce($identity, $candidates);
        }
        usort(
            $quarantined,
            fn (array $left, array $right): int => $this->canonicalJson($left) <=> $this->canonicalJson($right),
        );

        return ['items' => $items, 'quarantined_items' => $quarantined];
    }

    private function decisions(?array $arbitration): array
    {
        $grouped = [];
        foreach (is_array($arbitration['decisions'] ?? null) ? $arbitration['decisions'] : [] as $decision) {
            if (is_array($decision) && is_string($decision['claim_id'] ?? null)) {
                $grouped[$decision['claim_id']][] = $decision;
            }
        }
        ksort($grouped, SORT_STRING);
        $result = [];
        foreach ($grouped as $claimId => $decisions) {
            $result[$claimId] = $this->reduceDecisions($decisions);
        }

        return $result;
    }

    /** @param list<array<string, mixed>> $decisions @return array<string, mixed> */
    private function reduceDecisions(array $decisions): array
    {
        return $this->arbitrationPolicy->reduce($decisions);
    }

    private function evidence(mixed $items, array $scope): array
    {
        $result = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $locator = is_array($item) ? ($item['locator'] ?? null) : null;
            if (is_string($item['key'] ?? null) && is_array($locator)
                && ($locator['page_id'] ?? null) === $scope['page_id']
                && ($locator['page_number'] ?? null) === $scope['page_number']
                && ($locator['source_version'] ?? null) === $scope['source_version']) {
                $result[$item['key']] = $locator;
            }
        }

        return $result;
    }

    private function sameScope(mixed $source, array $scope): bool
    {
        return is_array($source)
            && ($source['document_id'] ?? null) === $scope['document_id']
            && ($source['page_id'] ?? null) === $scope['page_id']
            && ($source['page_number'] ?? null) === $scope['page_number']
            && ($source['source_version'] ?? null) === $scope['source_version'];
    }

    private function hasConditionalNote(array $observers, array $scope): bool
    {
        foreach ($observers as $observer) {
            if (! is_array($observer) || ! $this->sameScope($observer['source'] ?? null, $scope)) {
                continue;
            }
            foreach (is_array($observer['claims'] ?? null) ? $observer['claims'] : [] as $claim) {
                $value = is_array($claim) ? ($claim['value']['data'] ?? null) : null;
                if ($this->scopePolicy->isConditionalNote((string) ($claim['factType'] ?? ''), $value)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function estimateScope(string $category, string $value, bool $conditional): string
    {
        return $this->scopePolicy->scope($category, $value, $conditional);
    }

    /** @param list<array<string, mixed>> $candidates @return array<string, mixed> */
    private function reduce(string $identity, array $candidates): array
    {
        $objectTypes = array_values(array_unique(array_column($candidates, 'object_type')));
        sort($objectTypes, SORT_STRING);
        $objectType = $objectTypes[0];
        $categories = array_values(array_unique(array_column($candidates, 'category')));
        sort($categories, SORT_STRING);
        $category = $this->identity->canonicalCategory($objectType, $categories[0]);
        $quantity = $this->consensusQuantity(array_column($candidates, 'quantity_result'));
        $supportingClaimIds = [];
        $evidenceRefs = [];
        $evidenceLocators = [];
        foreach ($candidates as $candidate) {
            $supportingClaimIds = [...$supportingClaimIds, ...$candidate['supporting_claim_ids']];
            $evidenceRefs = [...$evidenceRefs, ...$candidate['evidence_refs']];
            $evidenceLocators[$this->canonicalJson($candidate['evidence_locator'])] = $candidate['evidence_locator'];
        }
        $supportingClaimIds = array_values(array_unique($supportingClaimIds));
        $evidenceRefs = array_values(array_unique($evidenceRefs));
        sort($supportingClaimIds, SORT_STRING);
        sort($evidenceRefs, SORT_STRING);
        ksort($evidenceLocators, SORT_STRING);
        $arbitration = $this->primaryArbitration($candidates);
        $primaryClaimIds = [];
        foreach ($candidates as $candidate) {
            if (($candidate['arbitration']['status'] ?? null) === $arbitration['status']
                && ($candidate['arbitration']['reason_code'] ?? null) === $arbitration['reason_code']) {
                $primaryClaimIds[] = $candidate['claim_id'];
            }
        }
        sort($primaryClaimIds, SORT_STRING);
        $roomKeys = array_values(array_unique(array_column($candidates, 'room_key')));
        sort($roomKeys, SORT_STRING);

        $result = [
            'key' => $identity,
            'label' => $this->identity->canonicalLabel($objectType, ''),
            'category' => $category,
            'object_type' => $objectType,
            'quantity' => $quantity->quantity,
            'quantity_uncertain' => $quantity->status !== 'count',
            'quantity_provenance' => $quantity->provenance,
            'room_key' => $roomKeys[0] ?? null,
            'scope' => $this->conservativeScope(array_column($candidates, 'scope')),
            'evidence_locator' => reset($evidenceLocators),
            'arbitration' => $arbitration,
            'lineage' => [
                'claim_id' => $primaryClaimIds[0] ?? $supportingClaimIds[0],
                'supporting_claim_ids' => $supportingClaimIds,
                'evidence_refs' => $evidenceRefs,
                'evidence_locators' => array_values($evidenceLocators),
            ],
        ];
        $classificationLimitations = array_values(array_unique(array_filter(
            array_column($candidates, 'classification_limitation'),
            'is_string',
        )));
        sort($classificationLimitations, SORT_STRING);
        if ($classificationLimitations !== []) {
            $result['classification_limitation'] = $classificationLimitations[0];
        }

        return $result;
    }

    /** @param list<VisualQuantityParseResult> $quantities */
    private function consensusQuantity(array $quantities): VisualQuantityParseResult
    {
        if ($quantities === []) {
            return new VisualQuantityParseResult('not_count', null, 'not_count');
        }
        $statuses = array_values(array_unique(array_map(
            static fn (VisualQuantityParseResult $result): string => $result->status,
            $quantities,
        )));
        $counts = array_values(array_unique(array_filter(array_map(
            static fn (VisualQuantityParseResult $result): ?int => $result->quantity,
            $quantities,
        ), 'is_int')));
        if ($statuses === ['count'] && count($counts) === 1) {
            return new VisualQuantityParseResult('count', $counts[0], 'explicit_count');
        }
        if ($statuses === ['not_count']) {
            return new VisualQuantityParseResult('not_count', null, 'not_count');
        }

        return new VisualQuantityParseResult('ambiguous', null, 'ambiguous');
    }

    /** @param list<string> $scopes */
    private function conservativeScope(array $scopes): string
    {
        $rank = [
            'excluded_by_document_note' => 0,
            'contextual_only' => 1,
            'requires_confirmation' => 2,
            'estimate_candidate' => 3,
        ];
        usort($scopes, static fn (string $left, string $right): int => ($rank[$left] ?? 0) <=> ($rank[$right] ?? 0));

        return $scopes[0] ?? 'requires_confirmation';
    }

    /** @param list<array<string, mixed>> $candidates @return array{status:string,reason_code:string} */
    private function primaryArbitration(array $candidates): array
    {
        return $this->arbitrationPresentation($this->arbitrationPolicy->reduce(array_column($candidates, 'arbitration')));
    }

    /** @param array<string, mixed> $decision @return array<string, string> */
    private function arbitrationPresentation(array $decision): array
    {
        $result = [
            'status' => (string) $decision['status'],
            'reason_code' => (string) $decision['reason_code'],
        ];
        if (is_string($decision['limitation_code'] ?? null)) {
            $result['limitation_code'] = $decision['limitation_code'];
        }

        return $result;
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
