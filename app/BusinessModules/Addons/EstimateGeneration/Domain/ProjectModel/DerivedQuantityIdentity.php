<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel;

final class DerivedQuantityIdentity
{
    public static function for(DerivedQuantity $quantity): string
    {
        $operands = $quantity->operands;
        usort($operands, static fn (array $left, array $right): int => [
            $left['role'] ?? '',
            $left['entity_id'] ?? '',
            $left['fact_id'],
            $left['projection_version'],
        ] <=> [
            $right['role'] ?? '',
            $right['entity_id'] ?? '',
            $right['fact_id'],
            $right['projection_version'],
        ]);

        return hash('sha256', self::canonicalJson([
            'logical_id' => $quantity->logicalId ?? $quantity->id,
            'formula' => $quantity->formula,
            'formula_identity' => $quantity->formulaIdentity,
            'formula_version' => $quantity->formulaVersion,
            'scope' => [
                'organization_id' => $quantity->organizationId,
                'project_id' => $quantity->projectId,
                'session_id' => $quantity->sessionId,
                'source_version' => $quantity->sourceVersion,
                'entity_id' => $quantity->entityId,
            ],
            'operands' => $operands,
            'value' => $quantity->value,
            'unit' => $quantity->unit,
            'status' => $quantity->status,
            'evidence_ids' => $quantity->evidenceIds,
            'rounding' => [
                'mode' => $quantity->roundingMode,
                'scale' => $quantity->roundingScale,
                'boundary' => $quantity->roundingBoundary,
            ],
            'unit_compatibility' => $quantity->unitCompatibility,
            'snapshot_identity' => $quantity->snapshotIdentity,
            'technology_decision_id' => $quantity->technologyDecisionId,
        ]));
    }

    public static function canonicalJson(mixed $value): string
    {
        return json_encode(self::canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
