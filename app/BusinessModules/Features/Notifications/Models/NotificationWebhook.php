<?php

namespace App\BusinessModules\Features\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Organization;

class NotificationWebhook extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'url',
        'secret',
        'events',
        'headers',
        'is_active',
        'timeout',
        'max_retries',
        'last_triggered_at',
        'success_count',
        'failure_count',
    ];

    protected $casts = [
        'events' => 'array',
        'headers' => 'array',
        'is_active' => 'boolean',
        'timeout' => 'integer',
        'max_retries' => 'integer',
        'success_count' => 'integer',
        'failure_count' => 'integer',
        'last_triggered_at' => 'datetime',
    ];

    protected $attributes = [
        'is_active' => true,
        'timeout' => 30,
        'max_retries' => 3,
        'success_count' => 0,
        'failure_count' => 0,
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeForEvent($query, string $event)
    {
        return $query->whereJsonContains('events', $event);
    }

    public function incrementSuccess(): void
    {
        $this->increment('success_count');
        $this->update(['last_triggered_at' => now()]);
    }

    public function incrementFailure(): void
    {
        $this->increment('failure_count');
    }
}

