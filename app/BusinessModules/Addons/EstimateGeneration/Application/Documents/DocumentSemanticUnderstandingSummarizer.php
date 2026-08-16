<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

final readonly class DocumentSemanticUnderstandingSummarizer
{
    private const MAX_FACTS = 500;

    private const MAX_ITEMS_PER_GROUP = 128;

    /** @param list<mixed> $payloads @return array<string, mixed> */
    public function summarize(array $payloads): array
    {
        $facts = [];
        $recommendations = [];
        $coverage = [];
        $entityPages = [];
        $roles = [];
        $quarantined = 0;
        $upstreamTruncated = false;
        $factOverflow = false;
        $roleRunsComplete = true;
        $arbitratedPageCount = 0;
        $schemaFourPageKinds = [];
        $acceptedQuantityClaimIds = [];

        foreach ($payloads as $payload) {
            if (! is_array($payload)) {
                continue;
            }
            if (($payload['schema_version'] ?? null) === 4) {
                $completion = is_array($payload['role_completion'] ?? null) ? $payload['role_completion'] : [];
                $routing = is_array($payload['analysis_routing'] ?? null) ? $payload['analysis_routing'] : [];
                $expectedRoles = is_array($routing['observer_roles'] ?? null)
                    ? array_values(array_filter($routing['observer_roles'], 'is_string'))
                    : [];
                if (($routing['arbiter_required'] ?? false) === true) {
                    $expectedRoles[] = 'arbiter';
                }
                $roleRunsComplete = $roleRunsComplete
                    && $expectedRoles !== []
                    && array_diff($expectedRoles, array_keys($completion)) === []
                    && ! in_array(false, array_intersect_key($completion, array_flip($expectedRoles)), true);
                $arbitratedPageCount++;
                $visionAnalysis = is_array($payload['vision_analysis'] ?? null) ? $payload['vision_analysis'] : [];
                $pageKind = is_string($routing['page_kind'] ?? null)
                    ? trim($routing['page_kind'])
                    : (is_string($visionAnalysis['sheet_type'] ?? null) ? trim($visionAnalysis['sheet_type']) : '');
                if ($pageKind !== '') {
                    $schemaFourPageKinds[$pageKind] = true;
                }
                foreach ($this->acceptedQuantityClaimIds($payload) as $claimId) {
                    $acceptedQuantityClaimIds[$claimId] = true;
                }

                continue;
            }
            $analysis = is_array($payload['vision_analysis'] ?? null) ? $payload['vision_analysis'] : [];
            $sheet = is_array($analysis['project_sheet_analysis'] ?? null)
                ? $analysis['project_sheet_analysis']
                : [];
            $quality = is_array($payload['semantic_quality'] ?? null) ? $payload['semantic_quality'] : [];
            $role = is_string($sheet['role'] ?? null) ? $sheet['role'] : 'unknown';
            $page = is_int($payload['page_number'] ?? null) ? $payload['page_number'] : null;
            $roles[$role] = ($roles[$role] ?? 0) + 1;
            $quarantined += is_array($quality['quarantined_items'] ?? null) ? count($quality['quarantined_items']) : 0;
            $upstreamTruncated = $upstreamTruncated || ($quality['truncated'] ?? false) === true;
            $coverage[] = [
                'page_number' => $page,
                'role' => $role,
                'checked' => self::boundedStrings($quality['checked'] ?? []),
                'found' => self::boundedStrings($quality['found'] ?? []),
                'missing' => self::boundedStrings($quality['missing'] ?? []),
                'needs_targeted' => self::boundedStrings($quality['needs_targeted'] ?? []),
            ];

            foreach (is_array($sheet['facts'] ?? null) ? $sheet['facts'] : [] as $fact) {
                if (! is_array($fact)) {
                    continue;
                }
                if (count($facts) >= self::MAX_FACTS) {
                    $factOverflow = true;

                    continue;
                }
                $item = [...$fact, 'page_number' => $page, 'role' => $role];
                $facts[] = $item;
                $factType = is_string($fact['factType'] ?? null) ? $fact['factType'] : '';
                if ($factType === 'recommendation' || $factType === 'technology_candidate') {
                    $recommendations[] = $item;
                }
                $entityKey = is_string($fact['entityKey'] ?? null) ? $fact['entityKey'] : '';
                if ($entityKey !== '' && $page !== null) {
                    $entityPages[$entityKey][$page] = true;
                }
            }
        }

        ksort($roles, SORT_STRING);
        ksort($entityPages, SORT_STRING);
        $connections = [];
        $connectionOverflow = false;
        foreach ($entityPages as $entityKey => $pages) {
            $pageNumbers = array_map('intval', array_keys($pages));
            sort($pageNumbers, SORT_NUMERIC);
            if (count($pageNumbers) > 1) {
                if (count($connections) >= self::MAX_ITEMS_PER_GROUP) {
                    $connectionOverflow = true;

                    continue;
                }
                $connections[] = ['entity_key' => $entityKey, 'pages' => $pageNumbers];
            }
        }

        $hasDrawingPages = array_intersect(
            array_keys($schemaFourPageKinds),
            ['drawing', 'floor_plan', 'plan', 'section', 'facade', 'detail'],
        ) !== [];
        $acceptedQuantityClaimCount = $roleRunsComplete ? count($acceptedQuantityClaimIds) : 0;
        $documentUnderstanding = $arbitratedPageCount === 0 ? null : [
            'document_type' => $hasDrawingPages ? 'drawing' : 'context',
            'role_for_estimation' => ! $roleRunsComplete
                ? 'needs_review'
                : ($hasDrawingPages ? 'drawing_analysis' : 'context_document'),
            'extracted_capabilities' => [
                'has_quantities' => $acceptedQuantityClaimCount > 0,
                'accepted_quantity_claims' => $acceptedQuantityClaimCount,
                'requires_manual_review' => ! $roleRunsComplete,
            ],
            'page_kinds' => array_values(array_keys($schemaFourPageKinds)),
            'pages_count' => $arbitratedPageCount,
        ];

        return [
            'pages_checked' => count($coverage) + $arbitratedPageCount,
            'roles' => $roles,
            'facts' => $facts,
            'analysis_roles_complete' => $arbitratedPageCount > 0 && $roleRunsComplete,
            ...($documentUnderstanding === null ? [] : ['document_understanding' => $documentUnderstanding]),
            'recommendations' => array_slice($recommendations, 0, self::MAX_ITEMS_PER_GROUP),
            'coverage' => array_slice($coverage, 0, self::MAX_ITEMS_PER_GROUP),
            'cross_page_connections' => $connections,
            'quarantined_count' => $quarantined,
            'truncated' => $upstreamTruncated
                || $factOverflow
                || count($recommendations) > self::MAX_ITEMS_PER_GROUP
                || count($coverage) > self::MAX_ITEMS_PER_GROUP
                || $connectionOverflow,
        ];
    }

    /** @return list<string> */
    private function acceptedQuantityClaimIds(array $payload): array
    {
        $claims = [];
        foreach (is_array($payload['independent_observations'] ?? null) ? $payload['independent_observations'] : [] as $observerRole => $observation) {
            if (! is_array($observation)) {
                continue;
            }
            foreach (is_array($observation['claims'] ?? null) ? $observation['claims'] : [] as $claimIndex => $claim) {
                if (! is_array($claim)) {
                    continue;
                }
                $claimId = is_string($claim['id'] ?? null) ? trim($claim['id']) : '';
                if ($claimId === '' && is_string($observerRole) && is_int($claimIndex)) {
                    $claimId = str_replace('observer_', '', $observerRole).':'.($claimIndex + 1);
                }
                $evidenceRef = is_string($claim['evidence_ref'] ?? null)
                    ? trim($claim['evidence_ref'])
                    : (is_string($claim['evidenceRef'] ?? null) ? trim($claim['evidenceRef']) : '');
                $factType = is_string($claim['fact_type'] ?? null)
                    ? $claim['fact_type']
                    : (is_string($claim['factType'] ?? null) ? $claim['factType'] : '');
                if (($claimId === '' && $evidenceRef === '')
                    || ! in_array($factType, ['area', 'quantity', 'length', 'height', 'count'], true)
                    || ! $this->isPositiveNumericClaimValue($claim['value'] ?? null)) {
                    continue;
                }
                $identity = $claimId !== '' ? 'claim:'.$claimId : 'evidence:'.$evidenceRef;
                $claims[$identity] = ['claim_id' => $claimId, 'evidence_ref' => $evidenceRef];
            }
        }

        $accepted = [];
        $arbitration = is_array($payload['document_arbitration'] ?? null) ? $payload['document_arbitration'] : [];
        foreach (is_array($arbitration['decisions'] ?? null) ? $arbitration['decisions'] : [] as $decision) {
            if (! is_array($decision) || ($decision['status'] ?? null) !== 'accepted') {
                continue;
            }
            $acceptedClaimIds = array_fill_keys(array_values(array_filter([
                is_string($decision['claim_id'] ?? null) ? trim($decision['claim_id']) : '',
                ...array_values(array_filter(
                    is_array($decision['supporting_claim_ids'] ?? null) ? $decision['supporting_claim_ids'] : [],
                    'is_string',
                )),
            ], static fn (string $value): bool => $value !== '')), true);
            $acceptedEvidenceRefs = array_fill_keys(array_values(array_filter(
                is_array($decision['evidence_refs'] ?? null) ? $decision['evidence_refs'] : [],
                static fn (mixed $value): bool => is_string($value) && $value !== '',
            )), true);
            foreach ($claims as $identity => $claim) {
                if (($claim['claim_id'] !== '' && isset($acceptedClaimIds[$claim['claim_id']]))
                    || ($claim['evidence_ref'] !== '' && isset($acceptedEvidenceRefs[$claim['evidence_ref']]))) {
                    $accepted[] = $identity;
                }
            }
        }

        return array_values(array_unique($accepted));
    }

    private function isPositiveNumericClaimValue(mixed $value): bool
    {
        if (is_array($value)) {
            if (($value['type'] ?? null) !== 'number') {
                return false;
            }
            $value = $value['data'] ?? null;
        }

        return (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)))
            && (float) $value > 0;
    }

    /** @return list<string> */
    private static function boundedStrings(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_slice(array_filter(
            $items,
            static fn (mixed $item): bool => is_string($item) && $item !== '' && strlen($item) <= 80,
        ), 0, self::MAX_ITEMS_PER_GROUP));
    }
}
