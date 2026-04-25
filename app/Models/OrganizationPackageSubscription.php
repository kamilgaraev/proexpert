<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationPackageSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'subscription_id',
        'is_bundled_with_plan',
        'package_slug',
        'tier',
        'price_paid',
        'activated_at',
        'expires_at',
    ];

    protected $casts = [
        'activated_at' => 'datetime',
        'expires_at' => 'datetime',
        'price_paid' => 'decimal:2',
        'is_bundled_with_plan' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(OrganizationSubscription::class, 'subscription_id');
    }

    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    public function scopeBundled($query)
    {
        return $query->where('is_bundled_with_plan', true);
    }

    public function scopeStandalone($query)
    {
        return $query->where('is_bundled_with_plan', false);
    }

    public function isBundled(): bool
    {
        return $this->is_bundled_with_plan === true;
    }
}
