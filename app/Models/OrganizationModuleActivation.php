<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationModuleActivation extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'organization_module_id',
        'activated_at',
        'expires_at',
        'status',
        'settings',
        'paid_amount',
        'payment_method',
    ];

    protected $casts = [
        'activated_at' => 'datetime',
        'expires_at' => 'datetime',
        'settings' => 'array',
        'paid_amount' => 'decimal:2',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(OrganizationModule::class, 'organization_module_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && 
               ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function getDaysUntilExpiration(): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        return now()->diffInDays($this->expires_at, false);
    }
} 