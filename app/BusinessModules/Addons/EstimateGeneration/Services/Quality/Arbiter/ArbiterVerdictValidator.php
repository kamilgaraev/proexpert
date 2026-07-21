<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter;

final class ArbiterVerdictValidator
{
    /** @param array<string, mixed> $raw
     *  @param array<string, mixed> $context
     */
    public function validate(array $raw, array $context): ArbiterVerdict
    {
        $outcome = $raw['outcome'] ?? null;
        if (! in_array($outcome, ['passed', 'targeted_rebuild', 'confirmed_scope_only', 'human_review'], true)) {
            return $this->humanReview($context, 'invalid_response');
        }
        $findings = [];
        foreach ((array) ($raw['findings'] ?? []) as $finding) {
            if (! is_array($finding)) {
                return $this->humanReview($context, 'invalid_response');
            }
            $scopeKey = $finding['scope_key'] ?? null;
            $packageKeys = $this->references($finding['package_keys'] ?? []);
            $evidenceRefs = $this->references($finding['evidence_refs'] ?? []);
            $action = $finding['action'] ?? null;
            $reasonCode = $finding['reason_code'] ?? null;
            if (! is_string($scopeKey) || ! in_array($scopeKey, (array) ($context['scope_keys'] ?? []), true)
                || ! in_array($action, ['rebuild', 'review'], true)
                || ! in_array($reasonCode, ['missing_component', 'evidence_required', 'quantity_unconfirmed'], true)) {
                return $this->humanReview($context, 'invalid_response', $scopeKey);
            }
            if (array_diff($packageKeys, (array) ($context['package_keys'] ?? [])) !== []
                || array_diff($evidenceRefs, (array) ($context['evidence_refs'] ?? [])) !== []) {
                return $this->humanReview($context, 'invalid_reference', $scopeKey);
            }
            if ($action === 'rebuild' && $evidenceRefs === []) {
                return $this->humanReview($context, 'evidence_required', $scopeKey);
            }
            $findings[] = [
                'scope_key' => $scopeKey,
                'package_keys' => $packageKeys,
                'evidence_refs' => $evidenceRefs,
                'action' => $action,
                'reason_code' => $reasonCode,
            ];
        }
        if ($outcome === 'targeted_rebuild' && array_filter(
            $findings,
            static fn (array $finding): bool => $finding['action'] === 'rebuild',
        ) === []) {
            return $this->humanReview($context, 'invalid_response');
        }

        return new ArbiterVerdict($outcome, $findings);
    }

    /** @param array<string, mixed> $context */
    private function humanReview(array $context, string $reasonCode, mixed $scopeKey = null): ArbiterVerdict
    {
        $scopeKey = is_string($scopeKey) && in_array($scopeKey, (array) ($context['scope_keys'] ?? []), true)
            ? $scopeKey
            : ($context['scope_keys'][0] ?? null);
        if (! is_string($scopeKey)) {
            return new ArbiterVerdict('human_review', []);
        }

        return new ArbiterVerdict('human_review', [[
            'scope_key' => $scopeKey,
            'package_keys' => [],
            'evidence_refs' => [],
            'action' => 'review',
            'reason_code' => $reasonCode,
        ]]);
    }

    /** @return list<string> */
    private function references(mixed $values): array
    {
        $result = [];
        foreach ((array) $values as $value) {
            if (is_string($value) && preg_match('/^[A-Za-z0-9:._-]{1,120}$/', $value) === 1) {
                $result[] = $value;
            }
        }

        return array_values(array_unique($result));
    }
}
