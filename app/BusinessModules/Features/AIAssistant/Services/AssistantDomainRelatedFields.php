<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\Models\Material;
use App\Models\WorkType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AssistantDomainRelatedFields
{
    public const PROJECTION = 'native_related_fields_v1';

    public static function fields(string $type): array
    {
        return match ($type) { 'completed_work' => ['name'], 'project_material_delivery' => ['name', 'material_unit'], default => [] };
    }

    public static function parentColumn(string $type): ?string
    {
        return match ($type) { 'completed_work' => 'work_type_id', 'project_material_delivery' => 'material_id', default => null };
    }

    public static function hydrate(iterable $models, string $type, int $organizationId): void
    {
        $column = self::parentColumn($type);
        if ($column === null) { return; }
        $models = is_array($models) ? $models : iterator_to_array($models);
        $ids = array_values(array_unique(array_filter(array_map(static fn (Model $model): mixed => $model->getAttribute($column), $models))));
        $query = $type === 'completed_work' ? WorkType::query() : Material::query();
        $query->where('organization_id', $organizationId)->whereKey($ids)->select(['id', 'organization_id', 'name', 'measurement_unit_id']);
        if ($type === 'project_material_delivery') {
            $query->with(['measurementUnit' => static fn (BelongsTo $units): BelongsTo => $units->where('organization_id', $organizationId)->select(['id', 'organization_id', 'name', 'short_name'])]);
        }
        $parents = $query->get()->keyBy('id');
        foreach ($models as $model) {
            $parent = $parents->get($model->getAttribute($column));
            $model->setAttribute('name', $parent?->name);
            if ($type === 'project_material_delivery') {
                $model->setAttribute('material_unit', $parent?->measurementUnit?->short_name ?? $parent?->measurementUnit?->name);
            }
        }
    }
}
