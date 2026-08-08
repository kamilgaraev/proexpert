<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;

final readonly class ReportActor
{
    public array $permissionSlugs;

    public function __construct(
        public int $id,
        public string $status,
        array $permissionSlugs,
    ) {
        if ($id < 1 || $status !== 'active') {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }

        $this->permissionSlugs = self::normalizePermissionSlugs($permissionSlugs);
    }

    private static function normalizePermissionSlugs(array $permissionSlugs): array
    {
        if (!array_is_list($permissionSlugs)) {
            throw new \InvalidArgumentException('report_actor_permissions_invalid');
        }

        $normalized = [];

        foreach ($permissionSlugs as $permissionSlug) {
            if (!is_string($permissionSlug)
                || preg_match('/^[a-z0-9][a-z0-9_-]*(?:\.[a-z0-9_-]+)*(?:\.\*)?$/D', $permissionSlug) !== 1
                || isset($normalized[$permissionSlug])) {
                throw new \InvalidArgumentException('report_actor_permissions_invalid');
            }

            $normalized[$permissionSlug] = $permissionSlug;
        }

        $normalized = array_values($normalized);
        sort($normalized, SORT_STRING);

        return $normalized;
    }
}
