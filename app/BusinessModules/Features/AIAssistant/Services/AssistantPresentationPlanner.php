<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final class AssistantPresentationPlanner
{
    private const MAX_PLAN_BYTES = 16_384;

    private const MAX_COLUMNS = 12;

    public static function isPlanCandidate(string $text): bool
    {
        if (strlen($text) > self::MAX_PLAN_BYTES) {
            return false;
        }
        try {
            $value = json_decode($text, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return is_array($value) && ($value['kind'] ?? null) === 'verified_rows';
    }

    public static function hasFinancialSelection(string $text): bool
    {
        if (! self::isPlanCandidate($text)) {
            return false;
        }
        $plan = json_decode($text, true);
        $moneyFields = array_merge(...AssistantFactIntentClassifier::requirements('Цена, сумма и бюджет'));
        foreach ($plan['result_sets'] ?? [] as $set) {
            if (is_array($set) && array_intersect(is_array($set['columns'] ?? null) ? $set['columns'] : [], $moneyFields) !== []) {
                return true;
            }
        }

        return false;
    }

    public function financialSelectionCovered(string $text, array $toolResults, array $financialSourceRefs): bool
    {
        if (! self::hasFinancialSelection($text)) {
            return true;
        }
        $plan = json_decode($text, true);
        $sets = $this->trustedSets($toolResults);
        if ($sets === []) {
            return false;
        }
        $moneyFields = array_merge(...AssistantFactIntentClassifier::requirements('Цена, сумма и бюджет'));
        $required = [];
        foreach ($plan['result_sets'] ?? [] as $setPlan) {
            if (! is_array($setPlan) || ! is_string($setPlan['result_set'] ?? null) || ! isset($sets[$setPlan['result_set']])
                || ! is_array($setPlan['columns'] ?? null) || ! is_array($setPlan['order'] ?? null)) {
                return false;
            }
            $selectedMoney = array_values(array_intersect($setPlan['columns'], $moneyFields));
            if ($selectedMoney === []) {
                continue;
            }
            foreach ($setPlan['order'] as $ref) {
                $row = is_string($ref) ? ($sets[$setPlan['result_set']]['rows'][$ref] ?? null) : null;
                if (! is_array($row)) {
                    return false;
                }
                $hasMoneyValue = false;
                foreach ($selectedMoney as $field) {
                    if (($row['fields'][$field] ?? null) !== null) {
                        $hasMoneyValue = true;
                        break;
                    }
                }
                if ($hasMoneyValue) {
                    $source = $row['source_ref'] ?? [];
                    $key = $this->financialSourceKey($source);
                    if ($key === null) {
                        return false;
                    }
                    $required[$key] = true;
                }
            }
        }
        if ($required === []) {
            return false;
        }
        $verified = [];
        foreach ($financialSourceRefs as $source) {
            if (is_array($source) && ($key = $this->financialSourceKey($source)) !== null) {
                $verified[$key] = true;
            }
        }

        return array_diff_key($required, $verified) === [];
    }

    public static function providerView(array $originalResult): ?array
    {
        $evidence = $originalResult['structured_fact_evidence'] ?? null;
        if (! is_array($evidence) || ! (new AssistantStructuredFactVerifier)->trustedEvidence($evidence)) {
            return null;
        }

        $fields = [];
        foreach ($evidence['rows'] as $row) {
            foreach (AssistantStructuredFactFormatter::presentationFields($row) as $field) {
                if (! in_array($field, $fields, true)) {
                    $fields[] = $field;
                }
            }
        }
        if ($evidence['rows'] === [] || $fields === []) {
            return null;
        }

        return ['kind' => 'verified_rows', 'version' => 1,
            'result_set' => 's_'.substr(hash('sha256', $evidence['version'].'|'.$evidence['fetched_at']), 0, 24),
            'fields' => $fields];
    }

    public function render(string $text, array $toolResults, ?string $query = null): ?string
    {
        if ($text === '' || strlen($text) > self::MAX_PLAN_BYTES) {
            return null;
        }
        try {
            $plan = json_decode($text, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (! is_array($plan) || ! $this->hasExactKeys($plan, ['kind', 'version', 'result_sets'])
            || ($plan['kind'] ?? null) !== 'verified_rows' || ($plan['version'] ?? null) !== 1
            || ! is_array($plan['result_sets'] ?? null) || ! array_is_list($plan['result_sets']) || $plan['result_sets'] === []) {
            return null;
        }

        $sets = $this->trustedSets($toolResults);
        if ($sets === [] || count($sets) !== count($plan['result_sets'])) {
            return null;
        }
        $selected = [];
        foreach ($plan['result_sets'] as $setPlan) {
            if (! is_array($setPlan) || ! $this->hasExactKeys($setPlan, ['result_set', 'layout', 'columns', 'order', 'group_by'])
                || ! is_string($setPlan['result_set'] ?? null) || ! isset($sets[$setPlan['result_set']])
                || isset($selected[$setPlan['result_set']]) || ! in_array($setPlan['layout'] ?? null, ['table', 'list'], true)
                || ! is_array($setPlan['columns'] ?? null) || ! array_is_list($setPlan['columns']) || $setPlan['columns'] === [] || count($setPlan['columns']) > self::MAX_COLUMNS
                || ! is_array($setPlan['order'] ?? null) || ! array_is_list($setPlan['order'])
                || (($setPlan['group_by'] ?? null) !== null && ! is_string($setPlan['group_by']))) {
                return null;
            }
            $set = $sets[$setPlan['result_set']];
            if (! $this->validColumns($set, $setPlan['columns']) || ! $this->exactPermutation($set, $setPlan['order'])) {
                return null;
            }
            $groupBy = $setPlan['group_by'];
            if ($groupBy !== null && (! is_string($groupBy) || ! in_array($groupBy, $setPlan['columns'], true)
                || ! $this->groupsStayContiguous($set, $setPlan['order'], $groupBy))) {
                return null;
            }
            $selected[$setPlan['result_set']] = true;
        }
        if (array_diff_key($sets, $selected) !== [] || ! $this->requirementsDisplayed($query ?? '', $sets, $plan['result_sets'])
            || ! $this->pairedMeasures($sets, $plan['result_sets'])) {
            return null;
        }

        $rendered = AssistantStructuredFactFormatter::renderVerifiedRows($sets, $plan);
        $hasPaymentRows = false;
        foreach ($sets as $set) {
            foreach ($set['rows'] as $row) {
                if (($row['entity_type'] ?? null) === 'payment_document') {
                    $hasPaymentRows = true;
                    break 2;
                }
            }
        }
        if ($hasPaymentRows) {
            $rendered .= "\n\n".trans_message('ai_assistant_facts.returned_scope');
        }

        return $rendered;
    }

    public function renderPaymentFallback(array $toolResults): ?string
    {
        $sets = $this->trustedSets($toolResults);
        if ($sets === []) {
            return null;
        }

        $rows = [];
        $commonFields = null;
        $hasVerifiedCurrency = false;
        foreach ($sets as $set) {
            foreach ($set['rows'] as $row) {
                if (($row['entity_type'] ?? null) !== 'payment_document') {
                    return null;
                }
                $fields = $row['_presentation_fields'];
                $commonFields = $commonFields === null ? $fields : array_values(array_intersect($commonFields, $fields));
                $hasVerifiedCurrency = $hasVerifiedCurrency || (in_array('currency', $fields, true)
                    && is_string($row['fields']['currency'] ?? null) && trim($row['fields']['currency']) !== '');
                $rows[] = $row;
            }
        }
        if ($rows === [] || $commonFields === null) {
            return null;
        }

        $identity = in_array('document_number', $commonFields, true) ? 'document_number'
            : (in_array('number', $commonFields, true) ? 'number' : null);
        $columns = $identity === null ? [] : [$identity];
        if (in_array('amount', $commonFields, true)) {
            $columns[] = 'amount';
        }
        if ($hasVerifiedCurrency) {
            $columns[] = 'currency';
        }
        foreach (['status', 'due_date', 'paid_at'] as $field) {
            if (in_array($field, $commonFields, true)) {
                $columns[] = $field;
            }
        }
        if ($columns === []) {
            return null;
        }

        $entityLabels = AssistantExtendedDomainRegistry::values('entityLabels');
        $entityLabel = $entityLabels['payment_document'] ?? trans_message('ai_assistant_facts.entities.payment_document');
        $headers = [trans_message('ai_assistant_facts.record')];
        foreach ($columns as $field) {
            $headers[] = trans_message('ai_assistant_facts.fields.'.$field);
        }
        $lines = ['| '.implode(' | ', $headers).' |',
            '| '.implode(' | ', array_fill(0, count($headers), '---')).' |'];
        foreach ($rows as $row) {
            $cells = [$entityLabel];
            foreach ($columns as $field) {
                $value = $row['fields'][$field] ?? null;
                if ($field === 'currency' && (! is_string($value) || trim($value) === '')) {
                    $cells[] = '';
                } else {
                    $cells[] = $value === null ? trans_message('ai_assistant_facts.unknown')
                        : AssistantStructuredFactFormatter::displayPresentationField($row, $field, $value);
                }
            }
            $lines[] = '| '.implode(' | ', $cells).' |';
        }
        $lines[] = '';
        $lines[] = trans_message('ai_assistant_facts.returned_scope');

        return implode("\n", $lines);
    }

    private function trustedSets(array $toolResults): array
    {
        $sets = [];
        $verifier = new AssistantStructuredFactVerifier;
        $uniqueRows = [];
        foreach ($toolResults as $result) {
            if (! is_array($result) || ! is_string($result['server_formatted_facts'] ?? null)
                || ! is_array($result['structured_fact_evidence'] ?? null)) {
                continue;
            }
            $evidence = $result['structured_fact_evidence'];
            if (! $verifier->trustedEvidence($evidence)) {
                continue;
            }
            $view = self::providerView($result);
            if ($view === null) {
                continue;
            }
            $rows = [];
            foreach ($evidence['rows'] as $index => $proofRow) {
                if (! is_array($proofRow)) {
                    return [];
                }
                $rowRef = 'r'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
                $fields = AssistantStructuredFactFormatter::presentationFields($proofRow);
                $rows[$rowRef] = $proofRow;
                $rows[$rowRef]['_presentation_fields'] = $fields;
                $identity = AssistantSourceReferenceIdentity::key($proofRow);
                if (! isset($uniqueRows[$identity])) {
                    if (count($uniqueRows) >= AssistantStructuredFactFormatter::MAX_ROWS) {
                        return [];
                    }
                    $uniqueRows[$identity] = true;
                }
            }
            $sets[$view['result_set']] = ['rows' => $rows];
        }

        return $sets;
    }

    private function validColumns(array $set, array $columns): bool
    {
        if (count(array_unique($columns, SORT_REGULAR)) !== count($columns)) {
            return false;
        }
        $availableFields = [];
        foreach ($set['rows'] as $row) {
            foreach ($row['_presentation_fields'] as $field) {
                if (array_key_exists($field, $row['fields'])) {
                    $availableFields[$field] = true;
                }
            }
        }
        foreach ($columns as $column) {
            if (! is_string($column) || ! isset($availableFields[$column])) {
                return false;
            }
        }

        return true;
    }

    private function exactPermutation(array $set, array $order): bool
    {
        $refs = array_keys($set['rows']);

        return count($order) === count($refs) && count(array_unique($order, SORT_REGULAR)) === count($order)
            && array_diff($refs, $order) === [] && array_diff($order, $refs) === [];
    }

    private function groupsStayContiguous(array $set, array $order, string $field): bool
    {
        $closed = [];
        $previous = null;
        foreach ($order as $ref) {
            $value = $set['rows'][$ref]['fields'][$field] ?? null;
            $key = json_encode($value, JSON_THROW_ON_ERROR);
            if ($key !== $previous && isset($closed[$key])) {
                return false;
            }
            if ($previous !== null && $key !== $previous) {
                $closed[$previous] = true;
            }
            $previous = $key;
        }

        return true;
    }

    private function requirementsDisplayed(string $query, array $sets, array $plans): bool
    {
        foreach (AssistantFactIntentClassifier::requirements($query) as $requiredFields) {
            $found = false;
            foreach ($plans as $plan) {
                $rows = $sets[$plan['result_set']]['rows'];
                foreach ($plan['columns'] as $column) {
                    if (! in_array($column, $requiredFields, true)) {
                        continue;
                    }
                    foreach ($rows as $row) {
                        if (($row['fields'][$column] ?? null) !== null) {
                            $found = true;
                            break 3;
                        }
                    }
                }
            }
            if (! $found) {
                return false;
            }
        }

        return true;
    }

    private function pairedMeasures(array $sets, array $plans): bool
    {
        $moneyFields = array_merge(...AssistantFactIntentClassifier::requirements('Цена, сумма и бюджет'));
        $quantityFields = ['quantity', 'quantity_total', 'total_quantity', 'completed_quantity', 'volume_completed', 'material_quantity', 'hours', 'hours_worked'];
        foreach ($plans as $plan) {
            $selected = $plan['columns'];
            $hasMoney = array_intersect($selected, $moneyFields) !== [];
            $hasQuantity = array_intersect($selected, $quantityFields) !== [];
            foreach ($sets[$plan['result_set']]['rows'] as $row) {
                if ($hasMoney && (! $this->hasSelectedNonEmptyValue($row, $selected, ['currency', 'budget_currency'])
                    || ! $this->selectedMoneyValuesHaveCurrency($row, $selected, $moneyFields))) {
                    return false;
                }
                if ($hasQuantity && ! $this->hasSelectedNonEmptyValue($row, $selected, ['unit', 'unit_name', 'unit_short_name', 'material_unit'])) {
                    return false;
                }
            }
        }

        return true;
    }

    private function selectedMoneyValuesHaveCurrency(array $row, array $selected, array $moneyFields): bool
    {
        foreach (array_intersect($selected, $moneyFields) as $field) {
            if (($row['fields'][$field] ?? null) !== null
                && ! $this->hasSelectedNonEmptyValue($row, $selected, ['currency', 'budget_currency'])) {
                return false;
            }
        }

        return true;
    }

    private function hasSelectedNonEmptyValue(array $row, array $selected, array $candidates): bool
    {
        foreach (array_intersect($selected, $candidates) as $field) {
            $value = $row['fields'][$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function financialSourceKey(array $source): ?string
    {
        if (! is_string($source['entity_type'] ?? null) && ! is_int($source['entity_type'] ?? null)) {
            return null;
        }
        if (! is_string($source['entity_id'] ?? null) && ! is_int($source['entity_id'] ?? null)) {
            return null;
        }
        if (! is_string($source['organization_id'] ?? null) && ! is_int($source['organization_id'] ?? null)) {
            return null;
        }

        return json_encode([(string) $source['entity_type'], (string) $source['entity_id'], (string) $source['organization_id']], JSON_THROW_ON_ERROR);
    }

    private function hasExactKeys(array $value, array $expected): bool
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);

        return $keys === $expected;
    }
}
