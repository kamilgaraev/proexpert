<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

final class CrmPipeline extends CrmModel
{
    protected $fillable = [
        'organization_id',
        'code',
        'label',
        'entity_type',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function stages(): HasMany
    {
        return $this->hasMany(CrmPipelineStage::class, 'pipeline_id')->orderBy('sort_order');
    }
}
