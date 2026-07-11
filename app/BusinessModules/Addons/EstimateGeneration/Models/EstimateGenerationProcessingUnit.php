<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Models;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitStatus;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class EstimateGenerationProcessingUnit extends Model
{
    protected $table = 'estimate_generation_processing_units';

    protected $guarded = [];

    protected $casts = [
        'unit_type' => DocumentUnitType::class,
        'status' => DocumentProcessingUnitStatus::class,
        'unit_index' => 'integer',
        'attempt_count' => 'integer',
        'output_count' => 'integer',
        'locator' => 'array',
        'metadata' => 'array',
        'lease_expires_at' => 'immutable_datetime',
        'started_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'failed_at' => 'immutable_datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(EstimateGenerationDocument::class, 'document_id');
    }

    public function page(): HasOne
    {
        return $this->hasOne(EstimateGenerationDocumentPage::class, 'processing_unit_id');
    }
}
