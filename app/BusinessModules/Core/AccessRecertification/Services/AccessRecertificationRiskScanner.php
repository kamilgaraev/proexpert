<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\AccessRecertification\Services;

final class AccessRecertificationRiskScanner
{
    private const CRITICAL_ROLE_SLUGS = [
        'super_admin',
        'system_admin',
        'organization_owner',
        'organization_admin',
    ];

    private const HIGH_RISK_PERMISSION_PREFIXES = [
        'payments.',
        'budgeting.',
        'procurement.',
        'warehouse.',
        'mdm.',
        'immutable_audit.events.view_sensitive',
        'access_recertification.',
    ];

    public function scan(string $roleSlug, array $permissions): array
    {
        $flags = [];
        $score = 0;

        if (in_array($roleSlug, self::CRITICAL_ROLE_SLUGS, true)) {
            $flags[] = 'critical_role';
            $score += 50;
        }

        if (in_array('*', $permissions, true) || $this->hasWildcardPermission($permissions)) {
            $flags[] = 'broad_permissions';
            $score += 35;
        }

        $highRiskPermissions = array_values(array_filter(
            $permissions,
            fn (string $permission): bool => $this->isHighRiskPermission($permission)
        ));

        if ($highRiskPermissions !== []) {
            $flags[] = 'high_risk_permissions';
            $score += min(40, count($highRiskPermissions) * 5);
        }

        $level = match (true) {
            $score >= 70 => 'critical',
            $score >= 40 => 'high',
            $score >= 20 => 'medium',
            default => 'low',
        };

        return [
            'level' => $level,
            'score' => $score,
            'flags' => array_values(array_unique($flags)),
            'high_risk_permissions' => $highRiskPermissions,
        ];
    }

    private function hasWildcardPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (is_string($permission) && str_ends_with($permission, '.*')) {
                return true;
            }
        }

        return false;
    }

    private function isHighRiskPermission(string $permission): bool
    {
        foreach (self::HIGH_RISK_PERMISSION_PREFIXES as $prefix) {
            if ($permission === $prefix || str_starts_with($permission, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
