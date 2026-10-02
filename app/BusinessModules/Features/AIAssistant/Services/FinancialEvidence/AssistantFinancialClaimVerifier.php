<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence;

use App\BusinessModules\Features\BudgetEstimates\Services\Finance\FinanceDecimal;

final class AssistantFinancialClaimVerifier
{
    public function verifyClaims(array $claims, array $evidence): array
    {
        if (($evidence['fetched_at'] ?? null) === null || ($evidence['source_refs'] ?? []) === [] || $claims === []) {
            return ['validation_status' => 'unverified', 'rejected_claims' => $claims];
        }
        $rejected = [];
        $positions = [];
        foreach ($evidence['positions'] ?? [] as $position) {
            $positions[$position['id']] = $position;
        }
        foreach ($claims as $claim) {
            if (! is_array($claim) || ($claim['estimate_id'] ?? null) !== ($evidence['estimate']['id'] ?? null)
                || ($claim['evidence_version'] ?? null) !== ($evidence['version'] ?? null)) {
                $rejected[] = $claim;
                continue;
            }
            $row = isset($claim['position_id']) ? ($positions[$claim['position_id']] ?? []) : ($evidence['totals'] ?? []);
            $field = $claim['field'] ?? '';
            $expected = is_string($field) ? ($row[$field] ?? null) : null;
            $actual = $claim['value'] ?? null;
            $matches = (is_string($actual) || is_int($actual)) && $expected !== null && (string) $expected === (string) $actual;
            if (! $matches && (is_string($actual) || is_int($actual)) && is_string($expected)
                && preg_match('/^-?\d+(?:\.\d+)?$/D', (string) $actual) && preg_match('/^-?\d+(?:\.\d+)?$/D', $expected)) {
                $matches = FinanceDecimal::compare($expected, (string) $actual) === 0;
            }
            if (! $matches) {
                $rejected[] = $claim;
            }
        }

        return ['validation_status' => $rejected !== [] ? 'partial' : ($evidence['validation_status'] ?? 'unverified'), 'rejected_claims' => $rejected];
    }

    public function verifyText(string $text, array $evidence, string $serverFormattedAnswer): array
    {
        $verified = $text === $serverFormattedAnswer && ($evidence['fetched_at'] ?? null) !== null && ($evidence['source_refs'] ?? []) !== [];

        return ['validation_status' => $verified ? ($evidence['validation_status'] ?? 'unverified') : 'unverified',
            'text' => $serverFormattedAnswer, 'replaced' => ! $verified];
    }

    public function guard(string $text, array $toolResults = []): array
    {
        $financial = (bool) preg_match('/(?:\d[\d\s\x{00A0}.,]*\s*(?:₽|руб|р\.|тыс|млн|млрд)|(?:сумм|стоимост|цен[а-яё]*|бюджет|позиц|объ[её]м|количеств)[^\n.!?]{0,100}\d)/iu', $text);
        if (! $financial) {
            return ['text' => $text, 'validation_status' => 'unverified', 'source_refs' => [], 'replaced' => false];
        }
        foreach ($toolResults as $result) {
            if (is_array($result) && is_string($result['server_formatted_answer'] ?? null)
                && is_array($result['financial_evidence'] ?? null)
                && ($result['financial_evidence']['fetched_at'] ?? null) !== null
                && ($result['financial_evidence']['source_refs'] ?? []) !== []) {
                return ['text' => $result['server_formatted_answer'],
                    'validation_status' => $result['financial_evidence']['validation_status'] ?? 'partial',
                    'source_refs' => $result['financial_evidence']['source_refs'], 'replaced' => $text !== $result['server_formatted_answer']];
            }
        }

        return ['text' => trans_message('ai_assistant_financial.unverified_claim'), 'validation_status' => 'partial', 'source_refs' => [], 'replaced' => true];
    }
}
