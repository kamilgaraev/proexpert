<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models;

use Illuminate\Database\Eloquent\Model;

final class AssistantEgressEnrollment extends Model
{
    protected $table = 'ai_assistant_egress_enrollments';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['sources' => 'array', 'input_context' => 'array', 'expires_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime'];
}
