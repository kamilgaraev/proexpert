<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\AssetCategory;
use Illuminate\Support\Facades\DB;

final class AssetCategoryService
{
    public function resolve(int $organizationId, ?string $name): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name ?? '') ?? '');
        if ($name === '') {
            return null;
        }

        $key = mb_strtolower($name, 'UTF-8');
        DB::table('asset_categories')->insertOrIgnore([
            'organization_id' => $organizationId,
            'name' => $name,
            'normalized_name' => $key,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return AssetCategory::query()->where('organization_id', $organizationId)
            ->where('normalized_name', $key)->firstOrFail()->name;
    }

    public function search(int $organizationId, string $search): array
    {
        $query = AssetCategory::query()->where('organization_id', $organizationId);
        $search = trim(preg_replace('/\s+/u', ' ', $search) ?? '');
        if ($search !== '') {
            $query->where('normalized_name', 'like', '%'.str_replace(
                ['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search, 'UTF-8'),
            ).'%');
        }

        return $query->orderBy('name')->limit(50)->get(['id', 'name'])->toArray();
    }
}
