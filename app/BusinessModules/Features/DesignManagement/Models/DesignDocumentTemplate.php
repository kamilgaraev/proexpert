<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Models;

use App\BusinessModules\Features\DesignManagement\Enums\DesignArtifactTypeEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignObjectTypeEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignProjectStageEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DesignDocumentTemplate extends Model
{
    protected $fillable = [
        'normative_source_id',
        'profile_code',
        'project_stage',
        'object_type',
        'section_code',
        'section_title',
        'document_code',
        'document_title',
        'artifact_type',
        'required',
        'sort_order',
        'allowed_formats',
        'sheet_registry_required',
        'normative_reference',
        'metadata',
    ];

    protected $casts = [
        'project_stage' => DesignProjectStageEnum::class,
        'object_type' => DesignObjectTypeEnum::class,
        'artifact_type' => DesignArtifactTypeEnum::class,
        'required' => 'boolean',
        'sort_order' => 'integer',
        'allowed_formats' => 'array',
        'sheet_registry_required' => 'boolean',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'required' => true,
        'sort_order' => 0,
        'allowed_formats' => '[]',
        'sheet_registry_required' => false,
        'metadata' => '{}',
    ];

    public function normativeSource(): BelongsTo
    {
        return $this->belongsTo(DesignNormativeSource::class, 'normative_source_id');
    }

    public function scopeForProfile(Builder $query, string $profileCode, string $projectStage, ?string $objectType): Builder
    {
        return $query
            ->where('profile_code', $profileCode)
            ->where('project_stage', $projectStage)
            ->where(static function (Builder $query) use ($objectType): void {
                $query->whereNull('object_type');

                if ($objectType !== null && $objectType !== '') {
                    $query->orWhere('object_type', $objectType);
                }
            });
    }
}
