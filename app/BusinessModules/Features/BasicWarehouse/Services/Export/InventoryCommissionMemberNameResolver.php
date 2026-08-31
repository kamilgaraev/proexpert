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

        $namesById = User::query()
            ->withTrashed()
            ->whereKey($memberIds->all())
            ->whereHas('organizations', static function ($query) use ($act): void {
                $query->where('organizations.id', $act->organization_id);
            })
            ->pluck('name', 'id');

        $names = [];
        foreach ($memberIds as $memberId) {
            $name = trim((string) $namesById->get($memberId));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }
}
