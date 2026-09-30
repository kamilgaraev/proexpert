<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final class AssistantStructuredFactVerifier
{
    public function guard(string $query, string $text, array $toolResults = []): array
    {
        if (! AssistantFactIntentClassifier::isFactual($query)) {
            return ['text' => $text, 'validation_status' => 'partial', 'source_refs' => [], 'replaced' => false, 'needs_clarification' => false];
        }
        return $this->verifiedResults($query, $text, $toolResults);
    }

    public function confirmedResults(array $toolResults): array
    {
        return $this->verifiedResults(null, '', $toolResults);
    }

    private function verifiedResults(?string $query, string $text, array $toolResults): array
    {
        $rows = [];
        $seen = [];
        $truncated = false;
        $positionPages = [];
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
            $fetchedAt ??= $evidence['fetched_at'];
            foreach ($evidence['rows'] as $row) {
                $key = AssistantSourceReferenceIdentity::key($row);
                if (isset($seen[$key])) {
                    continue;
                }
                if (count($rows) >= AssistantStructuredFactFormatter::MAX_ROWS) {
                    $truncated = true;
                    $estimateId = $row['source_ref']['estimate_id'] ?? null;
                    if ($row['entity_type'] === 'estimate_item' && isset($positionPages[$estimateId])) {
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
        foreach ($positionPages as $estimateId => $positionPage) {
            if (! $positionPage['incomplete']) {
                continue;
            }
            $shownIds = [];
            foreach ($rows as $row) {
                if ($row['entity_type'] === 'estimate_item' && (int) ($row['source_ref']['estimate_id'] ?? 0) === (int) $estimateId) {
                    $shownIds[(string) $row['entity_id']] = true;
                }
            }
            $shown = count($shownIds);
            $payload['server_formatted_facts'] .= "\n".($positionPage['inconsistent'] || $shown > $positionPage['total']
                ? trans_message('ai_assistant_facts.positions_partial_unknown')
                : trans_message('ai_assistant_facts.positions_partial', ['shown' => $shown, 'total' => $positionPage['total']]));
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
            $shown = count(array_filter($evidence['rows'], static fn (mixed $row): bool => is_array($row) && ($row['entity_type'] ?? null) === 'estimate_item'));
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

        return true;
    }
}
