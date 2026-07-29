<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Services;

use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingHierarchySnapshot;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Models\Organization;
use InvalidArgumentException;

final readonly class HoldingHierarchyResolver
{
    public function resolve(int $organizationId): HoldingHierarchySnapshot
    {
        $organization = Organization::query()->find($organizationId);
        if (! $organization instanceof Organization) {
            throw new InvalidArgumentException('holding_hierarchy_missing');
        }

        $holdingId = (int) ($organization->parent_organization_id ?: $organization->getKey());
        $members = Organization::query()
            ->where(static function ($query) use ($holdingId): void {
                $query->whereKey($holdingId)->orWhere('parent_organization_id', $holdingId);
            })
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'parent_organization_id', 'hierarchy_level', 'hierarchy_path'])
            ->map(static fn (Organization $member): array => [
                'id' => (int) $member->getKey(),
                'parent_id' => $member->parent_organization_id === null ? null : (int) $member->parent_organization_id,
                'level' => $member->hierarchy_level === null ? null : (int) $member->hierarchy_level,
                'path' => $member->hierarchy_path,
            ])
            ->all();
        $ids = array_column($members, 'id');
        if (! in_array($organizationId, $ids, true) || ! in_array($holdingId, $ids, true)) {
            throw new InvalidArgumentException('holding_hierarchy_missing');
        }

        return new HoldingHierarchySnapshot(
            $holdingId,
            hash('sha256', CanonicalJson::encode($members)),
            $ids,
        );
    }
}
