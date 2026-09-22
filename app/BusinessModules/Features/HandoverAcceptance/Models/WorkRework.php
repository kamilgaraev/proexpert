<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WorkRework extends Model
{
    protected $fillable = [
        'organization_id', 'project_id', 'acceptance_scope_id', 'quantity_line_id', 'quantity', 'unit_id',
        'responsible_user_id', 'created_by_user_id', 'verified_by_user_id', 'reason', 'status',
        'revision', 'correction_description', 'evidence_snapshot', 'financial_impact', 'submitted_at', 'verified_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:6', 'revision' => 'integer', 'evidence_snapshot' => 'array',
        'financial_impact' => 'array', 'submitted_at' => 'datetime', 'verified_at' => 'datetime',
    ];

    public function scope(): BelongsTo
    {
        return $this->belongsTo(AcceptanceScope::class, 'acceptance_scope_id');
    }

    public function quantityLine(): BelongsTo
    {
        return $this->belongsTo(AcceptanceScopeWorkQuantity::class, 'quantity_line_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(AcceptanceFinding::class, 'work_rework_id');
    }
}
