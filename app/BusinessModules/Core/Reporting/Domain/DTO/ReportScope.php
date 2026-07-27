<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use DateTimeZone;
use InvalidArgumentException;

final readonly class ReportScope
{
    public array $holdingOrganizationIds;

    public array $projectIds;

    public array $resourceIds;

    public function __construct(
        public int $organizationId,
        array $holdingOrganizationIds,
        array $projectIds,
        array $resourceIds,
        public DateTimeZone $timezone,
    ) {
        if ($organizationId < 1) {
            throw new InvalidArgumentException('report_scope_invalid');
        }

        $this->holdingOrganizationIds = self::normalizeIds($holdingOrganizationIds);
        $this->projectIds = self::normalizeIds($projectIds);
        $this->resourceIds = self::normalizeIds($resourceIds);

        if (!in_array($organizationId, $this->holdingOrganizationIds, true)) {
            throw new InvalidArgumentException('report_scope_holding_organization_missing');
        }
    }

    public function canonicalIdentity(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'holding_organization_ids' => $this->holdingOrganizationIds,
            'project_ids' => $this->projectIds,
            'resource_ids' => $this->resourceIds,
            'timezone' => $this->timezone->getName(),
        ];
    }

    private static function normalizeIds(array $ids): array
    {
        if (!array_is_list($ids)) {
            throw new InvalidArgumentException('report_scope_ids_invalid');
        }

        $normalized = [];

        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1 || isset($normalized[$id])) {
                throw new InvalidArgumentException('report_scope_ids_invalid');
            }

            $normalized[$id] = $id;
        }

        $normalized = array_values($normalized);
        sort($normalized, SORT_NUMERIC);

        return $normalized;
    }
}
