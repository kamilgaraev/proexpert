<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CrmPipelineStage extends CrmModel
{
    protected $fillable = [
        'pipeline_id',
        'code',
        'label',
        'category',
        'sort_order',
        'probability_percent',
        'required_fields',
        'required_links',
        'is_terminal',
    ];

    protected $casts = [
        'required_fields' => 'array',
        'required_links' => 'array',
        'is_terminal' => 'boolean',
    ];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(CrmPipeline::class, 'pipeline_id');
    }
}
