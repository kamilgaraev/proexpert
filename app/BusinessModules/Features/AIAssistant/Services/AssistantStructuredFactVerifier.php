<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantEstimateCompositionIntent;

final class AssistantStructuredFactVerifier
{
    public function guard(string $query, string $text, array $toolResults = [], array $requestScope = []): array
    {
        if (AssistantFactIntentClassifier::isProjectExpenseRequest($query)) {
            return $this->verifiedProjectExpenses($query, $text, $toolResults, $requestScope);
        }
        $planCandidate = AssistantPresentationPlanner::isPlanCandidate($text);
        if (AssistantDomainSearchCountEvidence::isCountRequest($query)) {
            return $this->verifiedCount($query, $text, $toolResults, $requestScope);
        }
        $compositionIntent = AssistantEstimateCompositionIntent::matches($query, $this->hasEstimateContext($toolResults));
        if (! $planCandidate && AssistantFactIntentClassifier::isNarrative($query)) {
            return ['text' => $text, 'validation_status' => 'partial', 'source_refs' => [], 'replaced' => false, 'needs_clarification' => false];
        }
        if (! AssistantFactIntentClassifier::isFactual($query) && ! $planCandidate
            && ! $compositionIntent) {
            return ['text' => $text, 'validation_status' => 'partial', 'source_refs' => [], 'replaced' => false, 'needs_clarification' => false];
        }
        if ($compositionIntent && $this->hasUnknownComposition($toolResults)) {
            $clarification = trans_message('ai_assistant_facts.composition_unverified');

            return ['text' => $clarification, 'validation_status' => 'partial', 'source_refs' => [],
                'replaced' => $text !== $clarification, 'needs_clarification' => true];
        }

        return $this->verifiedResults($query, $text, $toolResults);
    }

    public function confirmedResults(array $toolResults, ?string $query = null, array $requestScope = []): array
    {
        if ($query !== null && AssistantFactIntentClassifier::isProjectExpenseRequest($query)) {
            return $this->verifiedProjectExpenses($query, '', $toolResults, $requestScope);
        }
        if ($query !== null && AssistantDomainSearchCountEvidence::isCountRequest($query)) {
            return $this->verifiedCount($query, '', $toolResults, $requestScope);
        }
        return $this->verifiedResults(null, '', $toolResults, $query);
    }

    private function verifiedProjectExpenses(string $query, string $text, array $toolResults, array $requestScope): array
    {
        $unverified = ['text' => trans_message('ai_assistant_financial.project_expense_unverified'), 'validation_status' => 'partial',
            'source_refs' => [], 'replaced' => true, 'needs_clarification' => true, 'structured_evidence_truncated' => false];
        if (AssistantRequestProjectScope::organizationWide($query)
            || preg_match('/(?:планов|прогноз|(?:по|согласно)\s+плану)/iu', $query) === 1) { return $unverified; }
        $projectId = $requestScope['project_id'] ?? null;
        $period = (new \App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantPeriodResolver)->resolve($query);
        if (! is_int($projectId) || $projectId < 1 || $period?->dateFrom === null || $period->dateTo === null) { return $unverified; }
        $candidates = [];
        foreach ($toolResults as $result) {
            $evidence = is_array($result) ? ($result['financial_evidence'] ?? null) : null;
            if (is_array($result) && ($result['_tool_name'] ?? null) === 'get_live_project_financial_evidence'
                && ($result['source_covered'] ?? false) === true && is_array($evidence)
                && $this->validEmptyFinancialPeriod($evidence, $projectId, $period->dateFrom, $period->dateTo)) {
                $answer = trans_message('ai_assistant_financial.project_expense_no_records', ['from' => $period->dateFrom, 'to' => $period->dateTo]);
                $candidate = ['text' => $answer, 'validation_status' => 'verified', 'source_refs' => $evidence['source_refs'],
                    'replaced' => $text !== $answer, 'needs_clarification' => false, 'structured_evidence_truncated' => false];
                $candidates[AssistantSourceReferenceIdentity::key($candidate)] = $candidate;
                continue;
            }
            if (! is_array($result) || ($result['_tool_name'] ?? null) !== 'get_live_project_financial_evidence'
                || ($result['source_covered'] ?? false) !== true || ! is_array($evidence)
                || ($evidence['scope'] ?? null) !== 'authorized_project_period_totals'
                || ! is_array($evidence['rows'] ?? null) || $evidence['rows'] === []
                || ! in_array($evidence['validation_status'] ?? null, ['verified', 'partial'], true)
                || ($evidence['source_refs'] ?? null) !== array_column($evidence['rows'], 'source_ref')
                || ($evidence['version'] ?? null) !== AssistantSourceReferenceIdentity::key($evidence['rows'])) { continue; }
            $lines = [];
            $currencies = [];
            $valid = true;
            foreach ($evidence['rows'] as $row) {
                $ref = is_array($row) ? ($row['source_ref'] ?? null) : null;
                $key = is_array($ref) ? ($ref['composite_key'] ?? null) : null;
                $currency = is_array($row) ? ($row['currency'] ?? null) : null;
                $cost = is_array($row) ? ($row['fields']['actual_cost'] ?? null) : null;
                if (! is_array($row) || ! is_array($ref) || ! is_array($key)
                    || ($row['entity_type'] ?? null) !== 'live_project_financial_projection'
                    || ($ref['entity_type'] ?? null) !== 'live_project_financial_projection'
                    || ($ref['projection_name'] ?? null) !== 'live_project_financial_projection'
                    || ($row['entity_id'] ?? null) !== $projectId || ($ref['entity_id'] ?? null) !== $projectId
                    || ($key['project_id'] ?? null) !== $projectId || ($key['period_start'] ?? null) !== $period->dateFrom
                    || ($key['period_end'] ?? null) !== $period->dateTo || ! is_string($currency)
                    || ! in_array($currency, ['RUB', 'USD', 'EUR'], true) || ($key['currency'] ?? null) !== $currency
                    || isset($currencies[$currency]) || ! is_string($cost) || preg_match('/^-?\d+\.\d{2}$/D', $cost) !== 1
                    || ! is_string($ref['source_hash'] ?? null) || ! is_string($ref['fetched_at'] ?? null)
                    || ($row['source_version'] ?? null) !== ($ref['source_version'] ?? null)
                    || ($ref['source_version'] ?? null) !== AssistantSourceReferenceIdentity::key(['source_hash' => $ref['source_hash'],
                        'currency' => $currency, 'fields' => $row['fields']])) { $valid = false; break; }
                try {
                    $age = time() - (new \DateTimeImmutable($ref['fetched_at']))->getTimestamp();
                    if ($age < -60 || $age > 600) { $valid = false; break; }
                } catch (\Throwable) { $valid = false; break; }
                $currencies[$currency] = true;
                $lines[] = trans_message('ai_assistant_financial.project_expense_total', ['from' => $period->dateFrom,
                    'to' => $period->dateTo, 'amount' => $cost, 'currency' => $currency]);
            }
            if ($valid && $lines !== []) {
                $candidate = ['text' => implode("\n", $lines), 'validation_status' => 'verified', 'source_refs' => $evidence['source_refs'],
                    'replaced' => $text !== implode("\n", $lines), 'needs_clarification' => false, 'structured_evidence_truncated' => false];
                $candidates[AssistantSourceReferenceIdentity::key($candidate)] = $candidate;
            }
        }
        return count($candidates) === 1 ? reset($candidates) : $unverified;
    }

    private function validEmptyFinancialPeriod(array $evidence, int $projectId, string $from, string $to): bool
    {
        $absence = $evidence['absence'] ?? null;
        $ref = is_array($absence) ? ($absence['source_ref'] ?? null) : null;
        if (($evidence['scope'] ?? null) !== 'authorized_project_period_empty' || ($evidence['rows'] ?? null) !== []
            || ($evidence['validation_status'] ?? null) !== 'verified' || ! is_array($absence) || ! is_array($ref)
            || ($absence['record_count'] ?? null) !== 0 || ($evidence['source_refs'] ?? null) !== [$ref]
            || ($evidence['version'] ?? null) !== AssistantSourceReferenceIdentity::key($absence)
            || ($ref['entity_type'] ?? null) !== 'live_project_financial_projection'
            || ($ref['projection_name'] ?? null) !== 'live_project_financial_projection' || ($ref['entity_id'] ?? null) !== $projectId
            || ($ref['formula_version'] ?? null) !== 'native_project_margin_no_records_v1'
            || ($ref['evidence_time_basis'] ?? null) !== 'live_ledger_read'
            || ($ref['requested_currency'] ?? null) !== null
            || ($ref['composite_key'] ?? null) !== ['project_id' => $projectId, 'period_start' => $from, 'period_end' => $to, 'currency' => null]
            || ! is_string($ref['source_hash'] ?? null) || ! is_string($ref['fetched_at'] ?? null)
            || ($ref['source_version'] ?? null) !== AssistantSourceReferenceIdentity::key(['source_hash' => $ref['source_hash'], 'record_count' => 0])) {
            return false;
        }
        try {
            $age = time() - (new \DateTimeImmutable($ref['fetched_at']))->getTimestamp();

            return $age >= -60 && $age <= 600;
        } catch (\Throwable) {
            return false;
        }
    }

    private function verifiedCount(string $query, string $text, array $toolResults, array $requestScope = []): array
    {
        $counts = [];
        foreach ($toolResults as $result) {
            $evidence = is_array($result) ? ($result['domain_search_count_evidence'] ?? null) : null;
            if (is_array($evidence) && AssistantDomainSearchCountEvidence::trusted($evidence)
                && AssistantDomainSearchCountEvidence::matchesRequestedEntity($query, $evidence)
                && AssistantDomainSearchCountEvidence::matchesRequestedScope($query, $evidence, $requestScope)) {
                $counts[$evidence['source_ref']['entity_id']] = $evidence;
            }
        }
        if (count($counts) !== 1) {
            return ['text' => trans_message('ai_assistant_facts.live_proof_required'), 'validation_status' => 'partial',
                'source_refs' => [], 'replaced' => true, 'needs_clarification' => true, 'structured_evidence_truncated' => false];
        }
        $evidence = reset($counts);
        $key = ($evidence['source_ref']['_search_count_query']['predicate'] ?? null) === 'overdue' ? 'schedule_overdue_count'
            : (AssistantDomainSearchCountEvidence::isOrganizationWideRequest($query) ? 'search_record_count_organization'
            : (($evidence['source_ref']['project_id'] ?? null) !== null ? 'search_record_count_project' : 'search_record_count'));
        $formatted = trans_message('ai_assistant_facts.'.$key, ['count' => $evidence['value']]);

        return ['text' => $formatted, 'validation_status' => 'verified', 'source_refs' => [$evidence['source_ref']],
            'replaced' => $text !== $formatted, 'needs_clarification' => false, 'structured_evidence_truncated' => false];
    }

    private function verifiedResults(?string $query, string $text, array $toolResults, ?string $presentationQuery = null): array
    {
        $planner = new AssistantPresentationPlanner;
        $selectedRows = $planner->selectedRows($text, $toolResults, $presentationQuery ?? $query);
        if ($selectedRows === null && AssistantPresentationPlanner::isPlanCandidate($text)) {
            return ['text' => trans_message('ai_assistant_facts.live_proof_required'), 'validation_status' => 'partial',
                'source_refs' => [], 'replaced' => true, 'needs_clarification' => true, 'structured_evidence_truncated' => false];
        }
        if ($selectedRows !== null
            && ! AssistantEstimateCompositionIntent::matches($presentationQuery ?? $query ?? '', $this->hasEstimateContext($toolResults))) {
            $formatted = $planner->render($text, $toolResults, $presentationQuery ?? $query);
            $selectedSources = array_column($selectedRows, 'source_ref');
            $selectionTruncated = false;
            foreach ($toolResults as $result) {
                $evidence = is_array($result) ? ($result['structured_fact_evidence'] ?? []) : [];
                if (is_array($result) && AssistantPresentationPlanner::hasIncompleteScope($result) && $this->trusted($evidence)) {
                    foreach ($evidence['source_refs'] as $source) {
                        $selectionTruncated = $selectionTruncated || in_array($source, $selectedSources, true);
                    }
                }
            }

            return ['text' => $formatted, 'validation_status' => 'partial', 'source_refs' => $selectedSources,
                'replaced' => $text !== $formatted, 'needs_clarification' => false, 'structured_evidence_truncated' => $selectionTruncated];
        }
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
            $truncated = $truncated || AssistantPresentationPlanner::hasIncompleteScope($result);
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
                        'position_id' => $page['position_id'], 'total' => $page['total'], 'per_page' => $page['per_page'],
                        'inconsistent' => false, 'pages' => [], 'next_pages' => []];
                } elseif ($compositionPages[$key]['total'] !== $page['total']
                    || $compositionPages[$key]['project_id'] !== ($reference['project_id'] ?? null)
                    || $compositionPages[$key]['per_page'] !== $page['per_page']) {
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
        if ($selectedRows !== null) {
            $rows = $selectedRows;
        } else {
            $rows = $this->singleContractRows($presentationQuery ?? $query, $rows);
        }
        if ($rows === [] || ($query !== null && ! $this->requestedFactsPresent($query, $rows))) {
            if ($query !== null && AssistantFactIntentClassifier::isMoneyOnly($query) && ! AssistantFactIntentClassifier::requiresUnitPrice($query)) {
                $candidates = [];
                foreach ($toolResults as $result) {
                    $financial = is_array($result) ? ($result['financial_evidence'] ?? null) : null;
                    if (is_array($financial) && is_string($result['server_formatted_answer'] ?? null)
                        && $result['server_formatted_answer'] !== '' && is_string($financial['fetched_at'] ?? null)
                        && $financial['fetched_at'] !== '' && is_array($financial['source_refs'] ?? null)
                        && $financial['source_refs'] !== [] && is_string($financial['version'] ?? null)
                        && $financial['version'] !== '' && in_array($financial['validation_status'] ?? null, ['verified', 'partial'], true)) {
                        $candidate = ['text' => $result['server_formatted_answer'], 'validation_status' => $financial['validation_status'],
                            'source_refs' => $financial['source_refs'], 'replaced' => $text !== $result['server_formatted_answer'],
                            'needs_clarification' => false, 'structured_evidence_truncated' => $truncated];
                        $candidates[hash('sha256', json_encode($candidate, JSON_THROW_ON_ERROR))] = $candidate;
                    }
                }
                if (count($candidates) === 1) {
                    return reset($candidates);
                }
            }

            return ['text' => trans_message('ai_assistant_facts.live_proof_required'), 'validation_status' => 'partial',
                'source_refs' => [], 'replaced' => true, 'needs_clarification' => true, 'structured_evidence_truncated' => $truncated];
        }
        $payload = AssistantStructuredFactFormatter::payload($rows, $fetchedAt);
        $compositionSummaries = [];
        $compositionScopeResolved = $compositionPages !== [];
        foreach ($compositionPages as $compositionPage) {
            $summary = $this->compositionPageSummary($compositionPage, $rows);
            $compositionSummaries[] = $summary;
            if (! in_array($summary['status'], ['complete', 'beyond_end'], true)) {
                $compositionScopeResolved = false;
            }
        }
        if (! $this->compositionPresentationRowsOnly($rows, $compositionPages)) {
            $compositionScopeResolved = false;
        }
        $planner = new AssistantPresentationPlanner;
        $formatted = $planner->render($text, $toolResults, $presentationQuery ?? $query);
        if ($formatted === null && ! AssistantPresentationPlanner::isPlanCandidate($text)
            && ! AssistantFactIntentClassifier::isNarrative($presentationQuery ?? $query ?? '')) {
            $formatted = $planner->renderPaymentFallback($toolResults);
        }
        $payload['server_formatted_facts'] = $formatted ?? AssistantStructuredFactFormatter::presentation(
            $rows, $presentationQuery ?? $query, $compositionScopeResolved,
        );
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
        foreach ($compositionSummaries as $summary) {
            $message = match ($summary['status']) {
                'complete' => trans_message('ai_assistant_facts.composition_resources_complete', ['total' => $summary['total']]),
                'beyond_end' => trans_message('ai_assistant_facts.composition_resources_page_empty', [
                    'total' => $summary['total'], 'page' => $summary['page'],
                ]),
                'unknown' => trans_message('ai_assistant_facts.composition_resources_partial_unknown'),
                default => trans_message('ai_assistant_facts.composition_resources_partial', [
                    'shown' => $summary['shown'], 'total' => $summary['total'],
                ]),
            };
            $payload['server_formatted_facts'] .= "\n".$message;
        }
        if ($truncated && ! str_contains($payload['server_formatted_facts'], trans_message('ai_assistant_facts.returned_scope'))) {
            $payload['server_formatted_facts'] .= "\n\n".trans_message('ai_assistant_facts.returned_scope');
        }

        return ['text' => $payload['server_formatted_facts'], 'validation_status' => 'partial',
            'source_refs' => $payload['structured_fact_evidence']['source_refs'], 'replaced' => $text !== $payload['server_formatted_facts'],
            'needs_clarification' => false, 'structured_evidence_truncated' => $truncated];
    }

    private function singleContractRows(?string $query, array $rows): array
    {
        if ($query === null || preg_match('/(?<!\pL)договор[а-яё]*(?!\pL)/iu', $query) !== 1
            || preg_match('/(?<!\pL)(?:выбер[а-яё]*|покаж[а-яё]*|найд[а-яё]*|возьм[а-яё]*)\s+од(?:ин|ного|ному|ном)(?!\pL)|(?<!\pL)од(?:ин|ного|ному|ном)\s+договор/iu', $query) !== 1
            || preg_match('/(?:по\s+одному|кажд[а-яё]*|один\s+и\s+другой|од(?:ин|ного|ному|ном)\s+(?:подрядчик|проект|объект|плат[её]ж|сч[её]т|акт|смет))/iu', $query) === 1) { return $rows; }
        $candidates = [];
        foreach ($rows as $row) {
            if (($row['entity_type'] ?? null) === 'contract') { $candidates[(string) $row['entity_id']][] = $row; }
        }
        $selected = [];
        foreach ($candidates as $candidate) {
            if ($this->requestedFactsPresent($query, $candidate)) { $selected = $candidate; break; }
        }
        if ($selected === []) { return []; }
        $parties = [];
        foreach ($selected as $row) {
            $id = $row['fields']['contractor_id'] ?? null;
            if (isset($row['source_ref']['organization_id']) && (is_int($id) || is_string($id))
                && in_array('contractor_id', $row['source_ref']['checked_fields'] ?? [], true)) {
                $parties[(string) $row['source_ref']['organization_id'].'|'.(string) $id] = true;
            }
        }
        foreach ($rows as $row) {
            if (($row['entity_type'] ?? null) === 'contractor'
                && isset($row['source_ref']['organization_id'])
                && isset($parties[(string) $row['source_ref']['organization_id'].'|'.(string) $row['entity_id']])) {
                $selected[] = $row;
            }
        }
        return $selected;
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

    private function compositionPageSummary(array $compositionPage, array $rows): array
    {
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
        $expectedPageCount = max(1, (int) ceil($compositionPage['total'] / $compositionPage['per_page']));
        $expectedPagesMissing = false;
        for ($page = 1; $page <= $expectedPageCount; $page++) {
            if (! array_key_exists($page, $compositionPage['pages'])) {
                $expectedPagesMissing = true;
                break;
            }
        }
        if (! $compositionPage['inconsistent'] && $shown === $compositionPage['total']
            && ! $nextPagesMissing && ! $expectedPagesMissing) {
            return ['status' => 'complete', 'shown' => $shown, 'total' => $compositionPage['total'], 'page' => null];
        }
        $requestedPages = array_keys($compositionPage['pages']);
        if (! $compositionPage['inconsistent'] && $shown === 0 && count($requestedPages) === 1
            && $requestedPages[0] > $expectedPageCount) {
            return ['status' => 'beyond_end', 'shown' => $shown, 'total' => $compositionPage['total'], 'page' => $requestedPages[0]];
        }
        if ($compositionPage['inconsistent'] || $shown > $compositionPage['total']) {
            return ['status' => 'unknown', 'shown' => $shown, 'total' => $compositionPage['total'], 'page' => null];
        }

        return ['status' => 'partial', 'shown' => $shown, 'total' => $compositionPage['total'], 'page' => null];
    }

    private function compositionPresentationRowsOnly(array $rows, array $compositionPages): bool
    {
        if ($rows === [] || $compositionPages === []) {
            return false;
        }
        foreach ($rows as $row) {
            $matched = false;
            foreach ($compositionPages as $compositionPage) {
                $reference = $row['source_ref'] ?? null;
                if (! is_array($reference)) {
                    continue;
                }
                if ($row['entity_type'] === 'estimate') {
                    $matched = (string) $row['entity_id'] === (string) $compositionPage['estimate_id']
                        && ($reference['organization_id'] ?? null) === $compositionPage['organization_id']
                        && (! array_key_exists('project_id', $reference)
                            || $reference['project_id'] === $compositionPage['project_id']);
                } else {
                    $sameScope = ($reference['organization_id'] ?? null) === $compositionPage['organization_id']
                        && ($reference['estimate_id'] ?? null) === $compositionPage['estimate_id']
                        && array_key_exists('project_id', $reference)
                        && $reference['project_id'] === $compositionPage['project_id'];
                    $isPosition = $this->isBasePositionRow($row)
                        && (string) $row['entity_id'] === (string) $compositionPage['position_id'];
                    $isChild = $this->isCompositionChildRow($row, $compositionPage['position_id']);
                    $isIndependentResource = $row['entity_type'] === 'estimate_item_resource'
                        && ($reference['estimate_item_id'] ?? null) === $compositionPage['position_id']
                        && ($reference['composition_scope'] ?? null) === 'resources'
                        && ($reference['finance_representation'] ?? null) === 'independent';
                    $matched = $sameScope && ($isPosition || $isChild || $isIndependentResource);
                }
                if ($matched) {
                    break;
                }
            }
            if (! $matched) {
                return false;
            }
        }

        return true;
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

    private function hasUnknownComposition(array $toolResults): bool
    {
        foreach ($toolResults as $result) {
            if (is_array($result) && ($result['composition']['status'] ?? null) === 'unknown') {
                return true;
            }
        }

        return false;
    }

    public function trustedEvidence(array $evidence): bool
    {
        return $this->trusted($evidence);
    }
}
