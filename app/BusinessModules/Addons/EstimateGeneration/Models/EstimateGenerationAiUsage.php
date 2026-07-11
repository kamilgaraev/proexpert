<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EstimateGenerationAiUsage extends Model
{
    public $timestamps = false;

    protected $table = 'estimate_generation_ai_usage';

    protected $guarded = [];

    protected $casts = [
        'price_snapshot' => 'array',
        'created_at' => 'immutable_datetime',
        'page_id' => 'integer',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(EstimateGenerationDocumentPage::class, 'page_id');
    }
}
