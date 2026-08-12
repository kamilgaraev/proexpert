<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue;

use RuntimeException;

final class CanonicalEstimateCommandProposalResolver
{
    /** @param array<string, mixed> $context */
    public function resolve(EstimateCommandInterpretation $interpretation, array $context): EstimateCommandInterpretation
    {
        $payload = $interpretation->payload;
        $payload['context_fingerprint'] = $context['fingerprint'] ?? null;
        unset($payload['cost_delta'], $payload['cost_delta_known']);

        if ($interpretation->kind() === 'explain') {
            $target = (string) ($payload['target_key'] ?? '');
            if ($target !== '' && ! in_array($target, $context['allowed_references']['decision_keys'] ?? [], true)) {
                throw new RuntimeException('estimate_generation.command_reference_invalid');
            }
            $payload['evidence'] = $this->allowedEvidence($payload['evidence_ids'] ?? [], $context);

            return new EstimateCommandInterpretation($payload);
        }
        if ($interpretation->kind() === 'correct_fact') {
            $target = (string) ($payload['target_key'] ?? '');
            $fact = $this->find($context['facts'] ?? [], 'stable_key', $target);
            if ($fact === null || ! is_scalar($payload['value'] ?? null)) {
                throw new RuntimeException('estimate_generation.command_reference_invalid');
            }
            $payload['before'] = ['stable_key' => $target, 'value' => $fact['value'] ?? null, 'unit' => $fact['unit'] ?? null];
            $payload['after'] = [
                'source_version' => $fact['source_version'] ?? null,
                'value_fingerprint' => $fact['value_fingerprint'] ?? null,
                'assertion_stable_key' => $fact['assertion_stable_key'] ?? $target,
                'value' => ['value' => (string) $payload['value'], 'unit' => $fact['unit'] ?? null],
                'decision_version' => (int) ($fact['decision_version'] ?? 0),
            ];
            $payload['dependency_keys'] = [$target];
        } elseif ($interpretation->kind() === 'select_technology') {
            $decisionKey = (string) ($payload['decision_key'] ?? '');
            $optionId = (string) ($payload['option_id'] ?? '');
            $recommendation = $this->find($context['recommendations'] ?? [], 'decision_key', $decisionKey);
            $option = $recommendation === null ? null : $this->find($recommendation['options'] ?? [], 'id', $optionId, 'key');
            if ($recommendation === null || $option === null || ($option['applicable'] ?? true) !== true || ($option['availability'] ?? 'available') !== 'available') {
                throw new RuntimeException('estimate_generation.command_reference_invalid');
            }
            $payload['before'] = ['decision_key' => $decisionKey, 'selected_option' => $recommendation['selected_option'] ?? null];
            $payload['after'] = [
                'source_version' => $recommendation['source_version'] ?? '',
                'planning_run_id' => (int) ($recommendation['planning_run_id'] ?? 0),
                'decision_key' => $decisionKey,
                'response' => $optionId,
                'decision_version' => (int) ($recommendation['decision_version'] ?? 0),
            ];
            $payload['dependency_keys'] = [$decisionKey];
        }

        return new EstimateCommandInterpretation($payload);
    }

    private function find(array $items, string $field, string $value, ?string $fallback = null): ?array
    {
        foreach ($items as $item) {
            if (is_array($item) && ((string) ($item[$field] ?? ($fallback === null ? '' : ($item[$fallback] ?? '')))) === $value) {
                return $item;
            }
        }

        return null;
    }

    private function allowedEvidence(mixed $ids, array $context): array
    {
        $allowed = array_flip($context['allowed_references']['evidence_ids'] ?? []);
        $requested = array_flip(array_values(array_filter(array_map('strval', is_array($ids) ? $ids : []))));

        return array_values(array_filter($context['evidence'] ?? [], static fn (array $item): bool => isset($allowed[$item['id'] ?? ''], $requested[$item['id'] ?? ''])));
    }
}
