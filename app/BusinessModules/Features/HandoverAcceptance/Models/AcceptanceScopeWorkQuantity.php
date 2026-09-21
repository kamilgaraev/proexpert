<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Models;

use App\Models\CompletedWork;
use App\Models\MeasurementUnit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AcceptanceScopeWorkQuantity extends Model
{
    protected $fillable = [
        'organization_id', 'project_id', 'acceptance_scope_id', 'completed_work_id', 'unit_id',
        'presented_quantity', 'accepted_quantity', 'defect_quantity', 'defect_reason', 'revision',
        'created_by_user_id', 'updated_by_user_id', 'idempotency_key', 'payload_hash',
    ];

    protected $casts = [
        'presented_quantity' => 'decimal:6',
        'accepted_quantity' => 'decimal:6',
        'defect_quantity' => 'decimal:6',
        'revision' => 'integer',
    ];

    public function scope(): BelongsTo
    {
        return $this->belongsTo(\App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope::class, 'acceptance_scope_id');
    }

    public function completedWork(): BelongsTo
    {
        return $this->belongsTo(CompletedWork::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(MeasurementUnit::class, 'unit_id');
    }
}
