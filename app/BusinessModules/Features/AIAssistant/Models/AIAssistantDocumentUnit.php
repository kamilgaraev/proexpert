<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AIAssistantDocumentUnit extends Model
{
    protected $table = 'ai_assistant_document_units';
    protected $fillable = ['document_id', 'unit_type', 'unit_index', 'text', 'provenance', 'checksum', 'confidence'];
    protected $casts = ['document_id' => 'integer', 'unit_index' => 'integer', 'provenance' => 'array', 'confidence' => 'float'];
    public function document(): BelongsTo { return $this->belongsTo(AIAssistantDocument::class, 'document_id'); }
}
