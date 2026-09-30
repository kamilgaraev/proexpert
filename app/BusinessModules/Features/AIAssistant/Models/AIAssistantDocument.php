<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AIAssistantDocument extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY = 'ready';
    public const STATUS_OCR_QUOTE_REQUIRED = 'ocr_quote_required';
    public const STATUS_OCR_APPROVED = 'ocr_approved';
    public const STATUS_UNSUPPORTED = 'unsupported';
    public const STATUS_DAMAGED = 'damaged';
    public const STATUS_FAILED = 'failed';

    protected $table = 'ai_assistant_documents';

    protected $fillable = ['organization_id', 'project_id', 'file_id', 'parent_entity_type', 'parent_entity_id', 'storage_path', 'filename', 'mime_type', 'checksum', 'size_bytes', 'status', 'coverage_status', 'metadata', 'extracted_text', 'ocr_quote_id', 'ocr_reservation_id', 'ocr_approved_by', 'ocr_approved_at', 'processed_at', 'last_error'];

    protected $casts = ['organization_id' => 'integer', 'project_id' => 'integer', 'parent_entity_id' => 'string', 'size_bytes' => 'integer', 'metadata' => 'array', 'ocr_approved_by' => 'integer', 'ocr_approved_at' => 'datetime', 'processed_at' => 'datetime'];

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
}
