<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services;

final readonly class WorkforceCapacityDisclosurePolicy
{
    public function canViewAggregate(
        array $permissions,
        int $actorOrganizationId,
        ?array $permittedProjectIds,
        int $snapshotOrganizationId,
        ?int $snapshotProjectId,
    ): bool {
        if (! in_array('workforce.view', $permissions, true)
            || $actorOrganizationId !== $snapshotOrganizationId) {
            return false;
        }

        if ($permittedProjectIds === null) {
            return true;
        }

        return $snapshotProjectId !== null && in_array($snapshotProjectId, $permittedProjectIds, true);
    }

    public function canAuditLineage(
        array $permissions,
        int $actorOrganizationId,
        ?array $permittedProjectIds,
        int $snapshotOrganizationId,
        ?int $snapshotProjectId,
    ): bool {
        return in_array('workforce.audit.view', $permissions, true)
            && $this->canViewAggregate(
                $permissions,
                $actorOrganizationId,
                $permittedProjectIds,
                $snapshotOrganizationId,
                $snapshotProjectId,
            );
    }
}
