<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Services;

class MdmQualityService
{
    public function __construct(
        private readonly MdmNormalizationService $normalizationService,
        private readonly MdmQualityPolicyService $policyService
    ) {
    }

    public function evaluate(string $entityType, array $attributes, ?int $organizationId = null): array
    {
        $issues = [];
        $policy = $this->policyService->get($organizationId, $entityType);
        $requiredFields = $policy['required_fields'] ?? $this->requiredFields($entityType);
        $weights = $policy['field_weights'] ?? [];
        $normalized = $this->normalizationService->normalizeRecord($entityType, $attributes);

        foreach ($requiredFields as $field) {
            if (($attributes[$field] ?? null) === null || trim((string) $attributes[$field]) === '') {
                $issues[] = $this->issue($field . '_required', $field, (int) ($weights[$field] ?? 25));
            }
        }

        if (!empty($attributes['inn'] ?? $attributes['tax_number'] ?? null)) {
            $inn = $normalized['inn'] ?? $normalized['tax_number'] ?? '';
            if (!in_array(strlen($inn), [10, 12], true)) {
                $issues[] = $this->issue('inn_invalid', 'inn', (int) ($weights['inn'] ?? 20));
            }
        }

        if (!empty($attributes['kpp'] ?? null) && strlen($normalized['kpp'] ?? '') !== 9) {
            $issues[] = $this->issue('kpp_invalid', 'kpp', (int) ($weights['kpp'] ?? 10));
        }

        if (!empty($attributes['email'] ?? null) && !filter_var($normalized['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $issues[] = $this->issue('email_invalid', 'email', (int) ($weights['email'] ?? 10));
        }

        if (array_key_exists('default_price', $attributes) && $attributes['default_price'] !== null && (float) $attributes['default_price'] < 0) {
            $issues[] = $this->issue('price_negative', 'default_price', (int) ($weights['default_price'] ?? 15));
        }

        if (($normalized['normalized_key'] ?? null) === null) {
            $issues[] = $this->issue('identity_key_missing', 'normalized_key', (int) ($weights['normalized_key'] ?? 15));
        }

        $score = max(0, 100 - array_sum(array_column($issues, 'weight')));

        return [
            'score' => $score,
            'issues' => $issues,
            'normalized_values' => $normalized,
        ];
    }

    private function requiredFields(string $entityType): array
    {
        return match ($entityType) {
            'material', 'work_type' => ['name', 'measurement_unit_id'],
            'measurement_unit' => ['name', 'short_name', 'type'],
            'estimate_position' => ['name', 'code', 'measurement_unit_id'],
            default => ['name'],
        };
    }

    private function issue(string $code, string $field, int $weight): array
    {
        return [
            'code' => $code,
            'field' => $field,
            'weight' => $weight,
        ];
    }
}
