<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Services;

use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingHierarchySnapshot;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Models\Organization;
use DateTimeInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class HoldingHierarchyResolver
{
    public function __construct(
        private HoldingReportingSourceCoverage $coverage = new HoldingReportingSourceCoverage,
    ) {}

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

    public function resolveAt(int $organizationId, DateTimeInterface $asOf): HoldingHierarchySnapshot
    {
        if ($organizationId < 1) {
            throw new InvalidArgumentException('holding_hierarchy_missing');
        }

        $coverage = $this->coverage->assertCovers(
            HoldingReportingSourceCoverage::ORGANIZATION_HIERARCHY,
            $asOf,
        );
        $organization = DB::table('holding_organization_hierarchy_events')
            ->where('organization_id', $organizationId)
            ->where('observed_at', '<=', $asOf)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->first();
        if (! is_object($organization)
            || (bool) $organization->is_deleted
            || ! (bool) $organization->is_active) {
            throw new InvalidArgumentException('holding_hierarchy_missing');
        }

        $holdingId = (int) ($organization->parent_organization_id ?: $organization->organization_id);
        $candidateIds = DB::table('holding_organization_hierarchy_events')
            ->where('observed_at', '<=', $asOf)
            ->where(static function (QueryBuilder $scope) use ($holdingId): void {
                $scope
                    ->where('organization_id', $holdingId)
                    ->orWhere('parent_organization_id', $holdingId);
            })
            ->distinct()
            ->pluck('organization_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        if ($candidateIds === []) {
            throw new InvalidArgumentException('holding_hierarchy_missing');
        }

        $timeline = DB::table('holding_organization_hierarchy_events')
            ->select([
                'id',
                'organization_id',
                'parent_organization_id',
                'is_active',
                'hierarchy_level',
                'hierarchy_path',
                'is_deleted',
                'evidence_hash',
            ])
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY organization_id ORDER BY observed_at DESC, id DESC) AS timeline_position',
            )
            ->whereIn('organization_id', $candidateIds)
            ->where('observed_at', '<=', $asOf);
        $latest = DB::query()
            ->fromSub($timeline, 'latest_holding_hierarchy')
            ->where('timeline_position', 1)
            ->get();
        $members = $latest
            ->filter(static fn (object $event): bool => ! (bool) $event->is_deleted
                && (bool) $event->is_active
                && ((int) $event->organization_id === $holdingId
                    || (int) $event->parent_organization_id === $holdingId))
            ->sortBy(static fn (object $event): int => (int) $event->organization_id)
            ->map(static fn (object $event): array => [
                'id' => (int) $event->organization_id,
                'parent_id' => $event->parent_organization_id === null
                    ? null
                    : (int) $event->parent_organization_id,
                'level' => $event->hierarchy_level === null ? null : (int) $event->hierarchy_level,
                'path' => $event->hierarchy_path,
                'evidence_hash' => (string) $event->evidence_hash,
            ])
            ->values()
            ->all();
        $ids = array_column($members, 'id');
        if (! in_array($organizationId, $ids, true) || ! in_array($holdingId, $ids, true)) {
            throw new InvalidArgumentException('holding_hierarchy_missing');
        }

        return new HoldingHierarchySnapshot(
            $holdingId,
            hash('sha256', CanonicalJson::encode($members)),
            $ids,
            (string) $coverage['coverage_started_at'],
            array_values(array_column($members, 'evidence_hash')),
            (string) $coverage['evidence_hash'],
        );
    }
}
