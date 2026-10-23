<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models;

use Illuminate\Database\Eloquent\Model;

final class AssistantEgressCohort extends Model
{
    protected $table = 'ai_assistant_egress_cohorts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['allowed_tools' => 'array', 'expires_at' => 'immutable_datetime'];
}
