<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantEstimateCompositionIntent;

final class AssistantStructuredFactVerifier
{
    public function guard(string $query, string $text, array $toolResults = []): array
    {
        $planCandidate = AssistantPresentationPlanner::isPlanCandidate($text);
        $compositionIntent = AssistantEstimateCompositionIntent::matches($query, $this->hasEstimateContext($toolResults));
        if (! $planCandidate && AssistantFactIntentClassifier::isNarrative($query)) {
            return ['text' => $text, 'validation_status' => 'partial', 'source_refs' => [], 'replaced' => false, 'needs_clarification' => false];
        }
        if (! AssistantFactIntentClassifier::isFactual($query) && ! $planCandidate
            && ! $compositionIntent) {
            return ['text' => $text, 'validation_status' => 'partial', 'source_refs' => [], 'replaced' => false, 'needs_clarification' => false];
        }
        return $this->verifiedResults($query, $text, $toolResults);
    }

    public function confirmedResults(array $toolResults, ?string $query = null): array
    {
        return $this->verifiedResults(null, '', $toolResults, $query);
    }

    private function verifiedResults(?string $query, string $text, array $toolResults, ?string $presentationQuery = null): array
    {
        $rows = [];
        $seen = [];
        $truncated = false;
        $positionPages = [];
        $compositionPages = [];
        $fetchedAt = null;
        foreach ($toolResults as $result) {
            if (! is_array($result) || ! is_string($result['server_formatted_facts'] ?? null) || ! is_array($result['structured_fact_evidence'] ?? null)) {
                continue;
            }
            $evidence = $result['structured_fact_evidence'];
            if (! $this->trusted($evidence)) {
                continue;
            }
            if (is_array($evidence['position_page'] ?? null)) {
                $page = $evidence['position_page'];
                $estimateId = $page['estimate_id'];
                if (! isset($positionPages[$estimateId])) {
                    $positionPages[$estimateId] = ['total' => $page['total'], 'incomplete' => false, 'inconsistent' => false];
                } elseif ($positionPages[$estimateId]['total'] !== $page['total']) {
                    $positionPages[$estimateId]['inconsistent'] = true;
                }
                if ($evidence['truncated'] === true) {
                    $truncated = true;
                    $positionPages[$estimateId]['incomplete'] = true;
                }
            }
            if (is_array($evidence['composition_page'] ?? null)) {
                $page = $evidence['composition_page'];
                $position = current(array_filter($evidence['rows'], static fn (array $row): bool => $row['entity_type'] === 'estimate_item'
                    && ! array_key_exists('parent_work_id', $row['source_ref'] ?? [])
                    && (string) $row['entity_id'] === (string) $page['position_id']));
                if (! is_array($position) || ! is_array($position['source_ref'] ?? null)) {
                    continue;
                }
                $reference = $position['source_ref'];
                $key = json_encode([(string) $reference['organization_id'], (string) $reference['estimate_id'], (string) $page['position_id']], JSON_THROW_ON_ERROR);
                if (! isset($compositionPages[$key])) {
                    $compositionPages[$key] = ['organization_id' => $reference['organization_id'], 'estimate_id' => $reference['estimate_id'],
                        'project_id' => $reference['project_id'] ?? null,
                        'position_id' => $page['position_id'], 'total' => $page['total'], 'inconsistent' => false, 'pages' => [], 'next_pages' => []];
                } elseif ($compositionPages[$key]['total'] !== $page['total']
                    || $compositionPages[$key]['project_id'] !== ($reference['project_id'] ?? null)) {
                    $compositionPages[$key]['inconsistent'] = true;
                }
                $compositionPages[$key]['pages'][$page['page']] = true;
                if ($page['has_more'] && is_int($page['next_page'])) {
                    $compositionPages[$key]['next_pages'][$page['next_page']] = true;
                }
            }
            $fetchedAt ??= $evidence['fetched_at'];
            foreach ($evidence['rows'] as $row) {
                $key = AssistantSourceReferenceIdentity::key($row);
                if (isset($seen[$key])) {
                    continue;
                }
                if (count($rows) >= AssistantStructuredFactFormatter::MAX_ROWS) {
                    $truncated = true;
                    $estimateId = $row['source_ref']['estimate_id'] ?? null;
                    if ($this->isBasePositionRow($row) && isset($positionPages[$estimateId])) {
                        $positionPages[$estimateId]['incomplete'] = true;
                    }
                    continue;
                }
                $seen[$key] = true;
                $rows[] = $row;
            }
        }
        if ($rows === [] || ($query !== null && !$this->requestedFactsPresent($query, $rows))) {
            if ($query !== null && AssistantFactIntentClassifier::isMoneyOnly($query)) {
                foreach ($toolResults as $result) {
                    $financial = is_array($result) ? ($result['financial_evidence'] ?? null) : null;
                    if (is_array($financial) && is_string($result['server_formatted_answer'] ?? null)
                        && $result['server_formatted_answer'] !== '' && is_string($financial['fetched_at'] ?? null)
                        && $financial['fetched_at'] !== '' && is_array($financial['source_refs'] ?? null)
                        && $financial['source_refs'] !== [] && is_string($financial['version'] ?? null)
                        && $financial['version'] !== '' && in_array($financial['validation_status'] ?? null, ['verified', 'partial'], true)) {
                        return ['text' => $result['server_formatted_answer'], 'validation_status' => $financial['validation_status'],
                            'source_refs' => $financial['source_refs'], 'replaced' => $text !== $result['server_formatted_answer'],
                            'needs_clarification' => false, 'structured_evidence_truncated' => $truncated];
                    }
                }
            }
            return ['text' => trans_message('ai_assistant_facts.live_proof_required'), 'validation_status' => 'partial',
                'source_refs' => [], 'replaced' => true, 'needs_clarification' => true, 'structured_evidence_truncated' => $truncated];
        }
        $payload = AssistantStructuredFactFormatter::payload($rows, $fetchedAt);
        $planner = new AssistantPresentationPlanner;
        $formatted = $planner->render($text, $toolResults, $presentationQuery ?? $query);
        if ($formatted === null && ! AssistantPresentationPlanner::isPlanCandidate($text)
            && ! AssistantFactIntentClassifier::isNarrative($presentationQuery ?? $query ?? '')) {
            $formatted = $planner->renderPaymentFallback($toolResults);
        }
        $payload['server_formatted_facts'] = $formatted ?? AssistantStructuredFactFormatter::presentation($rows, $presentationQuery ?? $query);
        foreach ($positionPages as $estimateId => $positionPage) {
            if (! $positionPage['incomplete']) {
                continue;
            }
            $shownIds = [];
            foreach ($rows as $row) {
                if ($this->isBasePositionRow($row) && (int) ($row['source_ref']['estimate_id'] ?? 0) === (int) $estimateId) {
                    $shownIds[(string) $row['entity_id']] = true;
                }
            }
            $shown = count($shownIds);
            $payload['server_formatted_facts'] .= "\n".($positionPage['inconsistent'] || $shown > $positionPage['total']
                ? trans_message('ai_assistant_facts.positions_partial_unknown')
                : trans_message('ai_assistant_facts.positions_partial', ['shown' => $shown, 'total' => $positionPage['total']]));
        }
        foreach ($compositionPages as $compositionPage) {
            $shownIds = [];
            foreach ($rows as $row) {
                $reference = $row['source_ref'];
                $isResourceTableRow = $row['entity_type'] === 'estimate_item_resource'
                    && ($reference['estimate_item_id'] ?? null) === $compositionPage['position_id'];
                $isPositionChildRow = $this->isCompositionChildRow($row, $compositionPage['position_id']);
                if (($isResourceTableRow || $isPositionChildRow)
                    && ($reference['organization_id'] ?? null) === $compositionPage['organization_id']
                    && ($reference['estimate_id'] ?? null) === $compositionPage['estimate_id']
                    && ($reference['project_id'] ?? null) === $compositionPage['project_id']) {
                    $shownIds[$row['entity_type'].':'.(string) $row['entity_id']] = true;
                }
            }
            $shown = count($shownIds);
            $nextPagesMissing = array_diff_key($compositionPage['next_pages'], $compositionPage['pages']) !== [];
            if (! $compositionPage['inconsistent'] && $shown === $compositionPage['total'] && ! $nextPagesMissing) {
                continue;
            }
            $payload['server_formatted_facts'] .= "\n".($compositionPage['inconsistent'] || $shown > $compositionPage['total']
                ? trans_message('ai_assistant_facts.composition_resources_partial_unknown')
                : trans_message('ai_assistant_facts.composition_resources_partial', ['shown' => $shown, 'total' => $compositionPage['total']]));
        }

        return ['text' => $payload['server_formatted_facts'], 'validation_status' => 'partial',
            'source_refs' => $payload['structured_fact_evidence']['source_refs'], 'replaced' => $text !== $payload['server_formatted_facts'],
            'needs_clarification' => false, 'structured_evidence_truncated' => $truncated];
    }

    private function requestedFactsPresent(string $query, array $rows): bool
    {
        $fields = [];
        foreach ($rows as $row) {
            $fields = array_merge($fields, array_keys($row['fields']));
        }
        foreach (AssistantFactIntentClassifier::requirements($query) as $requiredFields) {
            if (array_intersect($fields, $requiredFields) === []) {
                return false;
            }
        }

        return true;
    }

    private function trusted(array $evidence): bool
    {
        if (($evidence['scope'] ?? null) !== 'returned_entity_fields' || ($evidence['validation_status'] ?? null) !== 'partial'
            || ! is_string($evidence['fetched_at'] ?? null) || $evidence['fetched_at'] === ''
            || ! is_array($evidence['rows'] ?? null) || $evidence['rows'] === []
            || ! is_array($evidence['source_refs'] ?? null) || $evidence['source_refs'] === []
            || count($evidence['rows']) > AssistantStructuredFactFormatter::MAX_ROWS
            || ($evidence['version'] ?? null) !== hash('sha256', json_encode($evidence['rows'], JSON_THROW_ON_ERROR))) {
            return false;
        }
        if (array_key_exists('truncated', $evidence) !== array_key_exists('position_page', $evidence)) {
            return false;
        }
        if (array_key_exists('truncated', $evidence)) {
            $page = $evidence['position_page'] ?? null;
            $shown = count(array_filter($evidence['rows'], fn (mixed $row): bool => is_array($row) && $this->isBasePositionRow($row)));
            if (! is_bool($evidence['truncated']) || ! is_array($page)
                || ! is_int($page['estimate_id'] ?? null)
                || ($evidence['rows'][0]['entity_type'] ?? null) !== 'estimate'
                || ($evidence['rows'][0]['entity_id'] ?? null) !== $page['estimate_id']
                || ! is_int($page['shown'] ?? null) || $page['shown'] !== $shown
                || ! is_int($page['returned'] ?? null) || $page['returned'] < $shown
                || ! is_int($page['total'] ?? null) || $page['total'] < $page['returned']
                || $evidence['truncated'] !== ($page['returned'] > $shown)) {
                return false;
            }
        }
        foreach ($evidence['rows'] as $row) {
            if (! is_array($row) || ! is_string($row['entity_type'] ?? null) || ! is_scalar($row['entity_id'] ?? null)
                || ! is_array($row['fields'] ?? null) || $row['fields'] === [] || ! is_string($row['version'] ?? null)
                || ! is_array($row['source_ref'] ?? null) || ($row['source_ref']['content_scope'] ?? null) !== 'structured'
                || ($row['source_ref']['entity_type'] ?? null) !== $row['entity_type']
                || (string) ($row['source_ref']['entity_id'] ?? '') !== (string) $row['entity_id']
                || ! in_array($row['source_ref'], $evidence['source_refs'], true)
                || ! is_array($row['source_ref']['checked_fields'] ?? null)
                || array_diff(array_keys($row['fields']), $row['source_ref']['checked_fields']) !== []
                || ($row['source_ref']['fetched_at'] ?? null) !== $evidence['fetched_at']) {
                return false;
            }
            if (isset($page) && $row['entity_type'] === 'estimate_item'
                && ($row['source_ref']['estimate_id'] ?? null) !== $page['estimate_id']) {
                return false;
            }
            foreach ($row['fields'] as $value) {
                if ($value !== null && ! is_string($value) && ! is_int($value) && ! is_bool($value)) {
                    return false;
                }
            }
        }

        return ! array_key_exists('composition_page', $evidence) || $this->validCompositionPage($evidence);
    }

    private function validCompositionPage(array $evidence): bool
    {
        $page = $evidence['composition_page'];
        if (! is_array($page)) {
            return false;
        }
        $keys = array_keys($page);
        $expectedKeys = ['scope', 'position_id', 'total', 'page', 'per_page', 'has_more', 'next_page'];
        sort($keys);
        sort($expectedKeys);
        if ($keys !== $expectedKeys || ($page['scope'] ?? null) !== 'resources'
            || ! is_int($page['position_id'] ?? null) || $page['position_id'] < 1
            || ! is_int($page['total'] ?? null) || $page['total'] < 0
            || ! is_int($page['page'] ?? null) || $page['page'] < 1
            || ! is_int($page['per_page'] ?? null) || $page['per_page'] < 1
            || ! is_bool($page['has_more'] ?? null)
            || (! is_int($page['next_page'] ?? null) && ($page['next_page'] ?? null) !== null)) {
            return false;
        }

        $positionRows = [];
        $resourceRows = [];
        foreach ($evidence['rows'] as $row) {
            if (! is_array($row)) {
                return false;
            }
            if ($row['entity_type'] === 'estimate_item') {
                $reference = $row['source_ref'];
                if ($this->isBasePositionRow($row)) {
                    if ((string) $row['entity_id'] !== (string) $page['position_id']) {
                        return false;
                    }
                    $positionRows[] = $row;
                } elseif (($reference['parent_work_id'] ?? null) === $page['position_id']
                    && ($reference['estimate_item_id'] ?? null) === $row['entity_id']
                    && ($reference['entity_id'] ?? null) === $row['entity_id']
                    && ($reference['composition_scope'] ?? null) === 'resources') {
                    $resourceRows[] = $row;
                } else {
                    return false;
                }
            }
            if ($row['entity_type'] === 'estimate_item_resource') {
                if (($row['source_ref']['estimate_item_id'] ?? null) !== $page['position_id']
                    || ($row['source_ref']['composition_scope'] ?? null) !== 'resources'
                    || ($row['source_ref']['finance_representation'] ?? null) !== 'independent') {
                    return false;
                }
                $resourceRows[] = $row;
            }
        }
        if (count($positionRows) !== 1) {
            return false;
        }
        $positionReference = $positionRows[0]['source_ref'] ?? null;
        if (! is_array($positionReference)) {
            return false;
        }
        $organizationId = $positionReference['organization_id'] ?? null;
        $estimateId = $positionReference['estimate_id'] ?? null;
        $projectId = $positionReference['project_id'] ?? null;
        if (! is_int($organizationId) || $organizationId < 1 || ! is_int($estimateId) || $estimateId < 1
            || ! array_key_exists('project_id', $positionReference)
            || ($projectId !== null && (! is_int($projectId) || $projectId < 1))) {
            return false;
        }
        foreach ($resourceRows as $row) {
            $reference = $row['source_ref'] ?? null;
            if (! is_array($reference) || ! array_key_exists('organization_id', $reference)
                || ! array_key_exists('estimate_id', $reference) || ! array_key_exists('project_id', $reference)) {
                return false;
            }
            if (($reference['organization_id'] ?? null) !== $organizationId
                || ($reference['estimate_id'] ?? null) !== $estimateId
                || ($reference['project_id'] ?? null) !== $projectId
                || ($row['entity_type'] === 'estimate_item' && (($reference['parent_work_id'] ?? null) !== $page['position_id']
                    || ($reference['estimate_item_id'] ?? null) !== $row['entity_id']))
                || ($row['entity_type'] === 'estimate_item_resource' && ($reference['estimate_item_id'] ?? null) !== $page['position_id'])) {
                return false;
            }
        }

        $resourceIds = array_map(static fn (array $row): string => $row['entity_type'].':'.(string) $row['entity_id'], $resourceRows);
        if (count(array_unique($resourceIds)) !== count($resourceIds)) {
            return false;
        }

        $offset = ($page['page'] - 1) * $page['per_page'];
        $expectedReturned = min($page['per_page'], max(0, $page['total'] - $offset));
        $hasMore = $offset + $expectedReturned < $page['total'];

        return count($resourceRows) === $expectedReturned && $page['has_more'] === $hasMore
            && $page['next_page'] === ($hasMore ? $page['page'] + 1 : null);
    }

    private function isBasePositionRow(array $row): bool
    {
        return ($row['entity_type'] ?? null) === 'estimate_item'
            && ! array_key_exists('parent_work_id', $row['source_ref'] ?? []);
    }

    private function isCompositionChildRow(array $row, int $positionId): bool
    {
        $reference = $row['source_ref'] ?? null;
        $entityId = filter_var($row['entity_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return ($row['entity_type'] ?? null) === 'estimate_item'
            && is_array($reference)
            && $entityId !== false
            && ($reference['entity_id'] ?? null) === $entityId
            && ($reference['estimate_item_id'] ?? null) === $entityId
            && ($reference['parent_work_id'] ?? null) === $positionId
            && ($reference['composition_scope'] ?? null) === 'resources';
    }

    private function hasEstimateContext(array $toolResults): bool
    {
        foreach ($toolResults as $result) {
            if (! is_array($result) || ! is_array($result['structured_fact_evidence']['rows'] ?? null)) {
                continue;
            }
            foreach ($result['structured_fact_evidence']['rows'] as $row) {
                if (is_array($row) && ($row['entity_type'] ?? null) === 'estimate') {
                    return true;
                }
            }
        }

        return false;
    }

    public function trustedEvidence(array $evidence): bool
    {
        return $this->trusted($evidence);
    }
}
