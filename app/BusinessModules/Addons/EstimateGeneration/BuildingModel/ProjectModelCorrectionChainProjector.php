<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\BuildingModelSchema;
use RuntimeException;

final class ProjectModelCorrectionChainProjector
{
    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{entity_stable_key: string, assertion_stable_key: string, assertion_type: string, value: array<string, mixed>, correction_stable_key: string}>
     */
    public function project(array $rows): array
    {
        $chains = [];
        foreach ($rows as $row) {
            $assertionKey = $this->string($row, 'assertion_stable_key');
            $entityKey = $this->string($row, 'entity_stable_key');
            $assertionType = $this->string($row, 'assertion_type');
            ProjectModelEntity::assertStableKey($assertionKey, 'Correction assertion');
            ProjectModelEntity::assertStableKey($entityKey, 'Correction entity');
            ProjectModelResolvedValue::assertAssertionType($assertionType);
            $initial = $this->assertionValue($this->array($row, 'assertion_payload'));
            $this->assertValue($assertionType, $initial);
            $chain = $chains[$assertionKey] ?? [
                'entity_stable_key' => $entityKey,
                'assertion_type' => $assertionType,
                'value' => $initial,
                'last_operation' => null,
                'correction_stable_key' => null,
            ];
            if ($chain['entity_stable_key'] !== $entityKey || $chain['assertion_type'] !== $assertionType) {
                throw new RuntimeException('project_model_correction_history_invalid');
            }
            $audit = $this->audit($this->array($row, 'correction_payload'));
            $operation = $audit['operation'] ?? null;
            $previous = $audit['previous_canonical_value'] ?? null;
            $next = $audit['new_canonical_value'] ?? null;
            if (! in_array($operation, ['apply', 'revert'], true) || ! is_array($previous) || ! is_array($next)
                || ! hash_equals(ProjectModelValueFingerprint::for($chain['value']), ProjectModelValueFingerprint::for($previous))
                || ! hash_equals(ProjectModelValueFingerprint::for($next), ProjectModelValueFingerprint::for($this->correctionValue($this->array($row, 'correction_payload'))))
                || ($operation === 'revert' && $chain['last_operation'] !== 'apply')) {
                throw new RuntimeException('project_model_correction_history_invalid');
            }
            $this->assertValue($assertionType, $next);
            $chain['value'] = $next;
            $chain['last_operation'] = $operation;
            $chain['correction_stable_key'] = $this->string($row, 'correction_stable_key');
            $chains[$assertionKey] = $chain;
        }

        ksort($chains, SORT_STRING);
        $effective = [];
        foreach ($chains as $assertionKey => $chain) {
            if ($chain['last_operation'] !== 'apply') {
                continue;
            }
            $effective[] = [
                'entity_stable_key' => $chain['entity_stable_key'],
                'assertion_stable_key' => $assertionKey,
                'assertion_type' => $chain['assertion_type'],
                'value' => $chain['value'],
                'correction_stable_key' => $chain['correction_stable_key'],
            ];
        }

        return $effective;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function assertionValue(array $payload): array
    {
        unset($payload['source']);
        ProjectModelEntity::assertObject($payload, 'Correction assertion value');

        return $payload;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function correctionValue(array $payload): array
    {
        $value = $payload['canonical_value'] ?? null;
        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException('project_model_correction_history_invalid');
        }

        return $value;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function audit(array $payload): array
    {
        $audit = $payload['audit'] ?? null;
        if (! is_array($audit) || array_is_list($audit) || ($audit['schema_version'] ?? null) !== 'project-model-correction:v1') {
            throw new RuntimeException('project_model_correction_history_invalid');
        }

        return $audit;
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException('project_model_correction_history_invalid');
        }

        return $value;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function array(array $row, string $key): array
    {
        $value = $row[$key] ?? null;
        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException('project_model_correction_history_invalid');
        }

        return $value;
    }

    /** @param array<string, mixed> $value */
    private function assertValue(string $assertionType, array $value): void
    {
        $valid = match ($assertionType) {
            'area' => $this->positiveNumber($value['value'] ?? null) && ($value['unit'] ?? null) === 'm2' && count($value) === 2,
            'dimension' => $this->positiveNumber($value['value'] ?? null)
                && is_string($value['unit'] ?? null)
                && in_array($value['unit'], ['m', 'm2', 'm3', 'pcs', 'kg', 't', 'h'], true) && count($value) === 2,
            'room_purpose' => is_string($value['value'] ?? null) && trim($value['value']) !== '' && mb_strlen($value['value']) <= 1000 && count($value) === 1,
            'opening' => in_array($value['type'] ?? null, ['door', 'window', 'gate'], true)
                && $this->positiveNumber($value['width_m'] ?? null) && $this->positiveNumber($value['height_m'] ?? null) && count($value) === 3,
            default => false,
        };
        if (! $valid) {
            throw new RuntimeException('project_model_correction_history_invalid');
        }
    }

    private function positiveNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value > 0;
    }
}
