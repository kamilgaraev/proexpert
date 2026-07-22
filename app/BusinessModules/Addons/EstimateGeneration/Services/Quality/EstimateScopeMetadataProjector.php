<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality;

final class EstimateScopeMetadataProjector
{
    private const MAX_SCOPES = 100;

    private const MAX_FINDINGS = 100;

    private const MAX_REFERENCES = 100;

    /** @return array<string, mixed> */
    public function project(array $draft, array $budgetScope): array
    {
        return [
            'completeness' => $this->completeness($draft['completeness'] ?? []),
            'budget_scope' => $this->budgetScope($budgetScope),
            'arbiter_review' => $this->arbiterReview($draft['arbiter_review'] ?? []),
        ];
    }

    /** @return array<string, mixed> */
    private function completeness(mixed $value): array
    {
        $completeness = is_array($value) ? $value : [];
        $status = in_array($completeness['status'] ?? null, [
            'full_confirmed_scope',
            'confirmed_scope_only',
            'review_required',
        ], true) ? $completeness['status'] : 'review_required';
        $scopes = [];

        foreach (array_slice(array_values((array) ($completeness['scopes'] ?? [])), 0, self::MAX_SCOPES) as $scope) {
            if (! is_array($scope) || ! $this->isReference($scope['key'] ?? null)) {
                continue;
            }

            $scopes[] = [
                'key' => (string) $scope['key'],
                'state' => in_array($scope['state'] ?? null, ['covered', 'excluded', 'unresolved'], true)
                    ? $scope['state']
                    : 'unresolved',
                'required_items' => $this->references($scope['required_items'] ?? []),
                'covered_items' => $this->references($scope['covered_items'] ?? []),
                'missing_items' => $this->references($scope['missing_items'] ?? []),
                'evidence_refs' => $this->references($scope['evidence_refs'] ?? []),
                'exclusion_reason' => in_array($scope['exclusion_reason'] ?? null, ['user_decision', 'document'], true)
                    ? $scope['exclusion_reason']
                    : null,
            ];
        }

        return ['status' => $status, 'scopes' => $scopes];
    }

    /** @return array<string, mixed> */
    private function budgetScope(array $budgetScope): array
    {
        $directCosts = $this->positiveOrZero($budgetScope['direct_costs'] ?? null);
        $overhead = $this->budgetComponent($budgetScope['overhead'] ?? []);
        $profit = $this->budgetComponent($budgetScope['profit'] ?? []);
        $commercial = $this->budgetComponent($budgetScope['commercial_budget'] ?? []);
        $claim = in_array($budgetScope['claim'] ?? null, [
            'commercial_budget',
            'confirmed_direct_costs',
            'confirmed_scope_only',
            'review_required',
        ], true) ? $budgetScope['claim'] : 'review_required';

        return [
            'direct_costs' => $directCosts,
            'overhead' => $overhead,
            'profit' => $profit,
            'commercial_budget' => $commercial,
            'claim' => $claim,
        ];
    }

    /** @return array{status: string, amount: ?float} */
    private function budgetComponent(mixed $component): array
    {
        $component = is_array($component) ? $component : [];
        $amount = $this->positiveOrZero($component['amount'] ?? null);

        if (($component['status'] ?? null) !== 'calculated' || $amount === null || $amount <= 0) {
            return ['status' => 'not_calculated', 'amount' => null];
        }

        return ['status' => 'calculated', 'amount' => $amount];
    }

    /** @return array<string, mixed> */
    private function arbiterReview(mixed $value): array
    {
        $review = is_array($value) ? $value : [];
        if ($review === []) {
            return [];
        }

        $result = [];
        foreach (['mode' => ['shadow', 'enforced'], 'status' => ['reviewed', 'unavailable', 'invalid'], 'outcome' => [
            'passed', 'targeted_rebuild', 'confirmed_scope_only', 'human_review',
        ]] as $key => $allowed) {
            if (in_array($review[$key] ?? null, $allowed, true)) {
                $result[$key] = $review[$key];
            }
        }
        if (! isset($result['mode'], $result['status'], $result['outcome'])) {
            return [];
        }
        if (is_string($review['input_hash'] ?? null) && preg_match('/^sha256:[0-9a-f]{64}$/', $review['input_hash']) === 1) {
            $result['input_hash'] = $review['input_hash'];
        }
        foreach (['prompt_version', 'schema_version', 'model'] as $key) {
            if ($this->isVersion($review[$key] ?? null)) {
                $result[$key] = $review[$key];
            }
        }
        foreach (['input_tokens', 'output_tokens'] as $key) {
            if (is_int($review[$key] ?? null) && $review[$key] >= 0 && $review[$key] <= 1_000_000) {
                $result[$key] = $review[$key];
            }
        }
        $findings = [];
        foreach (array_slice(array_values((array) ($review['findings'] ?? [])), 0, self::MAX_FINDINGS) as $finding) {
            if (! is_array($finding) || ! $this->isReference($finding['scope_key'] ?? null)
                || ! in_array($finding['action'] ?? null, ['rebuild', 'review'], true)
                || ! $this->isReference($finding['reason_code'] ?? null)) {
                continue;
            }
            $findings[] = [
                'scope_key' => $finding['scope_key'],
                'package_keys' => $this->references($finding['package_keys'] ?? []),
                'evidence_refs' => $this->references($finding['evidence_refs'] ?? []),
                'action' => $finding['action'],
                'reason_code' => $finding['reason_code'],
            ];
        }
        if ($findings !== []) {
            $result['findings'] = $findings;
        }

        return $result;
    }

    private function positiveOrZero(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value >= 0 ? round((float) $value, 2) : null;
    }

    /** @return list<string> */
    private function references(mixed $values): array
    {
        $references = [];
        foreach (array_slice(array_values((array) $values), 0, self::MAX_REFERENCES) as $value) {
            if ($this->isReference($value)) {
                $references[] = (string) $value;
            }
        }

        return array_values(array_unique($references));
    }

    private function isReference(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9:._-]{1,120}$/', $value) === 1;
    }

    private function isVersion(mixed $value): bool
    {
        return is_string($value) && preg_match('~^[A-Za-z0-9:._/-]{1,160}$~', $value) === 1;
    }
}
