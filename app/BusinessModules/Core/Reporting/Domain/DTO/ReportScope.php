<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use DateTimeZone;
use InvalidArgumentException;

final readonly class ReportScope
{
    public array $holdingOrganizationIds;

    public array $projectIds;

    public array $resources;

    public function __construct(
        public int $organizationId,
        array $holdingOrganizationIds,
        array $projectIds,
        array $resources,
        public DateTimeZone $timezone,
    ) {
        if ($organizationId < 1) {
            throw new InvalidArgumentException('report_scope_invalid');
        }

        $this->holdingOrganizationIds = self::normalizeIds($holdingOrganizationIds);
        $this->projectIds = self::normalizeIds($projectIds);
        $this->resources = self::normalizeResources($resources);

        if (! in_array($organizationId, $this->holdingOrganizationIds, true)) {
            throw new InvalidArgumentException('report_scope_holding_organization_missing');
        }
    }

    public function canonicalIdentity(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'holding_organization_ids' => $this->holdingOrganizationIds,
            'project_ids' => $this->projectIds,
            'resources' => array_map(
                static fn (ReportScopedResource $resource): array => $resource->canonicalIdentity(),
                $this->resources,
            ),
            'timezone' => $this->timezone->getName(),
        ];
    }

    private static function normalizeIds(array $ids): array
    {
        if (! array_is_list($ids)) {
            throw new InvalidArgumentException('report_scope_ids_invalid');
        }

        $normalized = [];

        foreach ($ids as $id) {
            if (! is_int($id) || $id < 1 || isset($normalized[$id])) {
                throw new InvalidArgumentException('report_scope_ids_invalid');
            }

            $normalized[$id] = $id;
        }

        $normalized = array_values($normalized);
        sort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    private static function normalizeResources(array $resources): array
    {
        if (! array_is_list($resources)) {
            throw new InvalidArgumentException('report_scope_resources_invalid');
        }

        $seen = [];
        foreach ($resources as $resource) {
            if (! $resource instanceof ReportScopedResource) {
                throw new InvalidArgumentException('report_scope_resources_invalid');
            }
            $identity = $resource->kind."\0".$resource->id."\0".($resource->projectId === null ? 'null' : (string) $resource->projectId);
            if (isset($seen[$identity])) {
                throw new InvalidArgumentException('report_scope_resources_invalid');
            }
            $seen[$identity] = true;
        }

        usort($resources, static function (ReportScopedResource $left, ReportScopedResource $right): int {
            $kind = strcmp($left->kind, $right->kind);
            if ($kind !== 0) {
                return $kind;
            }
            $id = $left->id <=> $right->id;
            if ($id !== 0) {
                return $id;
            }
            if ($left->projectId === null) {
                return $right->projectId === null ? 0 : -1;
            }
            if ($right->projectId === null) {
                return 1;
            }

            return $left->projectId <=> $right->projectId;
        });

        return $resources;
    }
}
