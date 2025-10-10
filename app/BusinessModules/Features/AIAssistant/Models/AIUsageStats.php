<?php

namespace App\BusinessModules\Features\AIAssistant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Organization;

class AIUsageStats extends Model
{
    protected $table = 'ai_usage_stats';

    protected $fillable = [
        'organization_id',
        'year',
        'month',
        'requests_count',
        'tokens_used',
        'cost_rub',
    ];

    protected $casts = [
        'requests_count' => 'integer',
        'tokens_used' => 'integer',
        'cost_rub' => 'decimal:2',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public static function getOrCreate(int $organizationId, int $year, int $month): self
    {
        return self::firstOrCreate(
            [
                'organization_id' => $organizationId,
                'year' => $year,
                'month' => $month,
            ],
            [
                'requests_count' => 0,
                'tokens_used' => 0,
                'cost_rub' => 0,
            ]
        );
    }

    public function incrementUsage(int $tokens, float $cost): void
    {
        $this->increment('requests_count');
        $this->increment('tokens_used', $tokens);
        $this->increment('cost_rub', $cost);
    }
}

