<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class OneCExchangeConflict extends Model
{
    protected $fillable = [
        'organization_id',
        'operation_id',
        'mapping_id',
        'assigned_to',
        'resolved_by',
        'conflict_key',
        'conflict_type',
        'status',
        'severity',
        'scope',
        'entity_type',
        'entity_id',
        'external_id',
        'title',
        'description',
        'source_hash',
        'payload_hash',
        'prohelper_values',
        'one_c_values',
        'safe_payload_preview',
        'resolution',
        'summary',
        'version',
        'detected_at',
        'due_at',
        'postponed_until',
        'resolved_at',
        'closed_at',
    ];

    protected $casts = [
        'prohelper_values' => 'array',
        'one_c_values' => 'array',
        'safe_payload_preview' => 'array',
        'resolution' => 'array',
        'summary' => 'array',
        'version' => 'integer',
        'detected_at' => 'datetime',
        'due_at' => 'datetime',
        'postponed_until' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(OneCExchangeOperation::class, 'operation_id');
    }

    public function mapping(): BelongsTo
    {
        return $this->belongsTo(OneCExchangeMapping::class, 'mapping_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resolvedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OneCExchangeConflictEvent::class, 'conflict_id');
    }
}
