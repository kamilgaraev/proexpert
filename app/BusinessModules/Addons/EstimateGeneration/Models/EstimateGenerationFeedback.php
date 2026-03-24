<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateGenerationFeedback extends Model
{
    protected $table = 'estimate_generation_feedback';

    protected $fillable = [
        'session_id',
        'user_id',
        'feedback_type',
        'section_key',
        'work_item_key',
        'payload',
        'comments',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(EstimateGenerationSession::class, 'session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
