<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Models;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EstimateGenerationAiUsage extends Model
{
    public $timestamps = false;

    protected $table = 'estimate_generation_ai_usage';

    protected $primaryKey = 'attempt_id';

    protected $keyType = 'string';

    public $incrementing = false;

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

    public function session(): BelongsTo
    {
        return $this->belongsTo(EstimateGenerationSession::class, 'session_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
