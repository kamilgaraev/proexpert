<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Models;

use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class AcceptanceFinding extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'acceptance_scope_id',
        'acceptance_session_id',
        'quality_defect_id',
        'created_by_user_id',
        'resolved_by_user_id',
        'title',
        'description',
        'severity',
        'status',
        'resolution_comment',
        'resolved_at',
        'metadata',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function scope(): BelongsTo
    {
        return $this->belongsTo(AcceptanceScope::class, 'acceptance_scope_id');
    }

    public function qualityDefect(): BelongsTo
    {
        return $this->belongsTo(QualityDefect::class, 'quality_defect_id');
    }
}
