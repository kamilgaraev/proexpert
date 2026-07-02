<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIUsageRecord extends Model
{
    protected $table = 'ai_usage_records';

    protected $fillable = [
        'organization_id',
        'user_id',
        'provider',
        'model',
        'operation',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'input_cost_rub',
        'output_cost_rub',
        'total_cost_rub',
        'currency',
        'metadata',
        'occurred_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'user_id' => 'integer',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'total_tokens' => 'integer',
        'input_cost_rub' => 'decimal:6',
        'output_cost_rub' => 'decimal:6',
        'total_cost_rub' => 'decimal:6',
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
