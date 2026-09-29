<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models;

use Illuminate\Database\Eloquent\Model;

final class AssistantDocumentSettings extends Model
{
    protected $table = 'ai_assistant_document_settings';
    protected $guarded = ['id'];
    protected $casts = ['background_ocr_enabled' => 'boolean', 'limit_minor' => 'integer', 'reserved_minor' => 'integer',
        'spent_minor' => 'integer', 'last_file_id' => 'integer', 'scanned_count' => 'integer', 'approved_at' => 'datetime', 'scan_completed_at' => 'datetime'];
}
