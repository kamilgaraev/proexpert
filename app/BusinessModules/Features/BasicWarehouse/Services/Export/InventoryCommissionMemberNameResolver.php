<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services\Export;

use App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct;
use App\Models\User;

final class InventoryCommissionMemberNameResolver
{
    /**
     * @return list<string>
     */
    public function resolve(InventoryAct $act): array
    {
        $memberIds = collect($act->commission_members ?? [])
            ->map(static function (mixed $memberId): ?int {
                if (is_int($memberId) && $memberId > 0) {
                    return $memberId;
                }

                if (is_string($memberId) && ctype_digit($memberId) && (int) $memberId > 0) {
                    return (int) $memberId;
                }

                return null;
            })
            ->filter(static fn (?int $memberId): bool => $memberId !== null)
            ->unique()
            ->values();

        if ($memberIds->isEmpty()) {
            return [];
        }

        $membersById = User::query()
            ->withTrashed()
            ->whereKey($memberIds->all())
            ->whereHas('organizations', static function ($query) use ($act): void {
                $query->where('organizations.id', $act->organization_id);
            })
            ->get(['id', 'name', 'position'])
            ->keyBy('id');

        $names = [];
        foreach ($memberIds as $memberId) {
            $member = $membersById->get($memberId);
            if ($member instanceof User) {
                $names[] = $this->resolveOfficialFullName($member);
            }
        }

        return $names;
    }

    private function resolveOfficialFullName(User $member): string
    {
        $name = trim((string) $member->name);
        if (preg_match('/^\p{L}[\p{L}\p{M}\'’.-]*(?:\s+\p{L}[\p{L}\p{M}\'’.-]*)+$/u', $name) === 1) {
            return $name;
        }

        $missingName = trans_message('basic_warehouse.inventory.commission_member_name_missing');
        $position = trim((string) $member->position);

        return $position === '' ? $missingName : "{$missingName} ({$position})";
    }
}
