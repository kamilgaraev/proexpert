<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstimateGenerationDocument extends Model
{
    protected $table = 'estimate_generation_documents';

    protected $fillable = [
        'session_id',
        'organization_id',
        'project_id',
        'user_id',
        'filename',
        'mime_type',
        'storage_path',
        'status',
        'processing_stage',
        'progress_percent',
        'file_size_bytes',
        'checksum_sha256',
        'source_version',
        'units_finalized_source_version',
        'units_reconciled_source_version',
        'units_reconcile_claim_token',
        'units_reconcile_lease_expires_at',
        'processing_control_status',
        'processing_control_source_version',
        'processing_control_attempt_id',
        'processing_control_reason',
        'processing_control_at',
        'processing_cost_limit',
        'processing_cost_confirmed_at',
        'processing_cost_confirmation_version',
        'page_count',
        'processed_page_count',
        'ocr_provider',
        'ocr_model',
        'ocr_attempts',
        'quality_score',
        'quality_level',
        'quality_flags',
        'facts_summary',
        'error_code',
        'error_message_key',
        'error_context',
        'ocr_started_at',
        'ocr_finished_at',
        'ignored_at',
        'extracted_text',
        'structured_payload',
        'meta',
    ];

    protected $casts = [
        'structured_payload' => 'array',
        'meta' => 'array',
        'progress_percent' => 'integer',
        'file_size_bytes' => 'integer',
        'page_count' => 'integer',
        'processed_page_count' => 'integer',
        'ocr_attempts' => 'integer',
        'quality_score' => 'float',
        'quality_flags' => 'array',
        'facts_summary' => 'array',
        'error_context' => 'array',
        'ocr_started_at' => 'datetime',
        'ocr_finished_at' => 'datetime',
        'ignored_at' => 'datetime',
        'units_reconcile_lease_expires_at' => 'immutable_datetime',
        'processing_control_at' => 'immutable_datetime',
        'processing_cost_limit' => 'decimal:8',
        'processing_cost_confirmed_at' => 'immutable_datetime',
        'processing_cost_confirmation_version' => 'integer',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(EstimateGenerationSession::class, 'session_id');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(EstimateGenerationDocumentPage::class, 'document_id')
            ->orderBy('page_number');
    }

    public function processingUnits(): HasMany
    {
        return $this->hasMany(EstimateGenerationProcessingUnit::class, 'document_id');
    }

    public function facts(): HasMany
    {
        return $this->hasMany(EstimateGenerationDocumentFact::class, 'document_id')
            ->orderBy('id');
    }

    public function drawingElements(): HasMany
    {
        return $this->hasMany(EstimateGenerationDrawingElement::class, 'document_id')
            ->orderBy('id');
    }

    public function quantityTakeoffs(): HasMany
    {
        return $this->hasMany(EstimateGenerationQuantityTakeoff::class, 'document_id')
            ->orderBy('id');
    }

    public function scopeInferences(): HasMany
    {
        return $this->hasMany(EstimateGenerationScopeInference::class, 'document_id')
            ->orderBy('id');
    }
}
