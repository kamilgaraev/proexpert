<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final class EstimateGenerationBuildingModel extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'estimate_generation_building_models';

    protected $guarded = ['*'];

    protected $casts = [
        'scale_meters_per_unit' => 'decimal:12',
        'model' => 'array',
        'assumptions' => 'array',
        'metrics' => 'array',
        'created_at' => 'immutable_datetime',
    ];

    protected function performUpdate(Builder $query): bool
    {
        throw new RuntimeException('estimate_generation.building_model_update_forbidden');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('estimate_generation.building_model_delete_forbidden');
    }
}
