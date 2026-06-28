<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Models;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateGenerationQuantityTakeoff extends Model
{
    protected $table = 'estimate_generation_quantity_takeoffs';

    protected $fillable = [
        'session_id',
        'document_id',
        'page_id',
        'organization_id',
        'project_id',
        'source_element_ids',
        'scope_key',
        'work_intent',
        'name',
        'unit',
        'quantity',
        'formula',
        'confidence',
        'source_refs',
        'normalized_payload',
    ];

    protected $casts = [
        'source_element_ids' => 'array',
        'work_intent' => 'array',
        'source_refs' => 'array',
        'normalized_payload' => 'array',
        'quantity' => 'float',
        'confidence' => 'float',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(EstimateGenerationSession::class, 'session_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EstimateGenerationDocument::class, 'document_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(EstimateGenerationDocumentPage::class, 'page_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
