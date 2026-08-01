<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use InvalidArgumentException;

final readonly class ReportPermissionPolicy
{
    public array $viewPermissions;

    public array $exportPermissions;

    public array $sensitivePermissions;

    public array $auditPermissions;

    public function __construct(
        array $viewPermissions,
        array $exportPermissions,
        array $sensitivePermissions,
        array $auditPermissions,
    ) {
        $this->viewPermissions = self::normalizePermissionSlugs($viewPermissions);
        $this->exportPermissions = self::normalizePermissionSlugs($exportPermissions);
        $this->sensitivePermissions = self::normalizePermissionSlugs($sensitivePermissions);
        $this->auditPermissions = self::normalizePermissionSlugs($auditPermissions);

        if ($this->viewPermissions === [] || $this->exportPermissions === []) {
            throw new InvalidArgumentException('report_permission_policy_required_permissions_missing');
        }
    }

    private static function normalizePermissionSlugs(array $permissionSlugs): array
    {
        if (!array_is_list($permissionSlugs)) {
            throw new InvalidArgumentException('report_permission_policy_invalid');
        }

        $normalized = [];

        foreach ($permissionSlugs as $permissionSlug) {
            if (!is_string($permissionSlug) || preg_match('/^[a-z0-9][a-z0-9._-]+$/', $permissionSlug) !== 1 || isset($normalized[$permissionSlug])) {
                throw new InvalidArgumentException('report_permission_policy_invalid');
            }

            $normalized[$permissionSlug] = $permissionSlug;
        }

        $normalized = array_values($normalized);

        sort($normalized, SORT_STRING);

        return $normalized;
    }
}
