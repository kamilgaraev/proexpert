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
        'module_id',
        'subscription_id',
        'is_bundled_with_plan',
        'status',
        'activated_at',
        'expires_at',
        'trial_ends_at',
        'last_used_at',
        'paid_amount',
        'payment_details',
        'next_billing_date',
        'module_settings',
        'usage_stats',
        'cancelled_at',
        'cancellation_reason',
        'is_auto_renew_enabled',
    ];

    protected $casts = [
        'activated_at' => 'datetime',
        'expires_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'last_used_at' => 'datetime',
        'next_billing_date' => 'datetime',
        'cancelled_at' => 'datetime',
        'payment_details' => 'array',
        'module_settings' => 'array',
        'usage_stats' => 'array',
        'paid_amount' => 'decimal:2',
        'is_bundled_with_plan' => 'boolean',
        'is_auto_renew_enabled' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(OrganizationSubscription::class, 'subscription_id');
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

    public function isTrial(): bool
    {
        return $this->status === 'trial' && 
               ($this->trial_ends_at === null || $this->trial_ends_at->isFuture());
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function getDaysUntilExpiration(): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        $diff = now()->diffInDays($this->expires_at, false);
        return $diff >= 0 ? (int) $diff : 0;
    }

    public function getDaysUntilTrialEnd(): ?int
    {
        if ($this->trial_ends_at === null) {
            return null;
        }

        $diff = now()->diffInDays($this->trial_ends_at, false);
        return $diff >= 0 ? (int) $diff : 0;
    }

    public function updateLastUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }

    public function addUsageStats(string $action, array $metadata = []): void
    {
        $stats = $this->usage_stats ?? [];
        $today = now()->format('Y-m-d');
        
        if (!isset($stats[$today])) {
            $stats[$today] = [];
        }
        
        if (!isset($stats[$today][$action])) {
            $stats[$today][$action] = 0;
        }
        
        $stats[$today][$action]++;
        
        if (!empty($metadata)) {
            if (!isset($stats[$today]['metadata'])) {
                $stats[$today]['metadata'] = [];
            }
            $stats[$today]['metadata'][] = $metadata;
        }
        
        $this->update(['usage_stats' => $stats]);
    }

    public function getUsageCount(string $action, ?string $date = null): int
    {
        $stats = $this->usage_stats ?? [];
        $targetDate = $date ?? now()->format('Y-m-d');
        
        return $stats[$targetDate][$action] ?? 0;
    }

    public function getTotalUsageCount(string $action): int
    {
        $stats = $this->usage_stats ?? [];
        $total = 0;
        
        foreach ($stats as $date => $dayStats) {
            if (isset($dayStats[$action])) {
                $total += $dayStats[$action];
            }
        }
        
        return $total;
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
    }

    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<', now());
    }

    public function scopeExpiring($query, int $days = 7)
    {
        return $query->where('status', 'active')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays($days)]);
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

    public function isStandalone(): bool
    {
        return !$this->isBundled();
    }

    public function syncWithSubscription(OrganizationSubscription $subscription): bool
    {
        if (!$this->isBundled()) {
            return false;
        }

        return $this->update([
            'subscription_id' => $subscription->id,
            'expires_at' => $subscription->ends_at,
            'next_billing_date' => $subscription->next_billing_at,
        ]);
    }

    public function convertToBundled(OrganizationSubscription $subscription): bool
    {
        return $this->update([
            'is_bundled_with_plan' => true,
            'subscription_id' => $subscription->id,
            'expires_at' => $subscription->ends_at,
            'next_billing_date' => $subscription->next_billing_at,
            'paid_amount' => 0,
        ]);
    }

    public function convertToStandalone(): bool
    {
        return $this->update([
            'is_bundled_with_plan' => false,
            'subscription_id' => null,
        ]);
    }

    public function deactivateAsBundled(string $reason = 'Подписка отменена'): bool
    {
        if (!$this->isBundled()) {
            return false;
        }

        return $this->update([
            'status' => 'suspended',
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);
    }
}