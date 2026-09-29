<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models;

use Illuminate\Database\Eloquent\Model;

final class RagExpectedSource extends Model
{
    protected $table = 'ai_rag_expected_sources';

    protected $guarded = [];

    protected $casts = [
        'organization_id' => 'integer',
        'project_id' => 'integer',
        'identity_project_id' => 'integer',
        'pending_since' => 'immutable_datetime',
    ];
}
