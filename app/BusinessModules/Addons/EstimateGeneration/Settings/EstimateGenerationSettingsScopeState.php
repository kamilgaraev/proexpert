<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Settings;

use DomainException;

final class EstimateGenerationSettingsScopeState
{
    /**
     * @param  array<string, mixed>  $defaults
     * @param  array{scope: string, organization_id: int|null, version: int, snapshot: array<string, mixed>}|null  $current
     * @return array<string, mixed>
     */
    public static function compose(array $defaults, string $scope, ?int $organizationId, ?array $current, string $idempotencyKey): array
    {
        if (! in_array($scope, ['global', 'organization'], true)
            || ($scope === 'global' && $organizationId !== null)
            || ($scope === 'organization' && ($organizationId === null || $organizationId <= 0))) {
            throw new DomainException('estimate_generation_settings_scope_invalid');
        }
        if ($current !== null
            && ($current['scope'] !== $scope || $current['organization_id'] !== $organizationId
                || $current['version'] <= 0 || ! is_array($current['snapshot']))) {
            throw new DomainException('estimate_generation_settings_scope_snapshot_mismatch');
        }

        return [
            ...$defaults,
            ...($current['snapshot'] ?? []),
            'scope' => $scope,
            'organization_id' => $organizationId,
            'expected_version' => $current['version'] ?? 0,
            'idempotency_key' => $idempotencyKey,
        ];
    }
}
