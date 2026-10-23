<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models;

use Illuminate\Database\Eloquent\Model;

final class AssistantDocumentProcessingPolicy extends Model
{
    protected $table = 'ai_assistant_document_processing_policies';

    protected $guarded = [];

    protected $casts = ['organization_id' => 'integer', 'project_id' => 'integer', 'document_id' => 'integer',
        'actor_user_id' => 'integer', 'assigned_by' => 'integer'];
}
