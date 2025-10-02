<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'subscription_plan_id',
        'status',
        'trial_ends_at',
        'starts_at',
        'ends_at',
        'next_billing_at',
        'canceled_at',
        'payment_failure_notified_at',
        'payment_gateway_subscription_id',
        'payment_gateway_customer_id',
        'is_auto_payment_enabled',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'next_billing_at' => 'datetime',
        'canceled_at' => 'datetime',
        'payment_failure_notified_at' => 'datetime',
        'is_auto_payment_enabled' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function bundledModules(): HasMany
    {
        return $this->hasMany(OrganizationModuleActivation::class, 'subscription_id')
            ->where('is_bundled_with_plan', true);
    }

    public function activeBundledModules(): HasMany
    {
        return $this->bundledModules()
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Проверить, активна ли подписка (не истекла и не отменена)
     */
    public function isActive(): bool
    {
        return $this->status === 'active' 
            && $this->ends_at > now()
            && !$this->isCanceled();
    }

    /**
     * Проверить, отменена ли подписка
     */
    public function isCanceled(): bool
    {
        return $this->canceled_at !== null;
    }

    /**
     * Проверить, истекла ли подписка
     */
    public function isExpired(): bool
    {
        return $this->ends_at <= now();
    }

    /**
     * Получить статус подписки с учетом отмены и срока действия
     */
    public function getEffectiveStatus(): string
    {
        if ($this->isExpired()) {
            return 'expired';
        }
        
        if ($this->isCanceled()) {
            return 'canceled_active';
        }
        
        return $this->status;
    }

    public function syncModulesExpiration(): int
    {
        return $this->bundledModules()->update([
            'expires_at' => $this->ends_at,
            'next_billing_date' => $this->next_billing_at,
        ]);
    }

    public function deactivateBundledModules(string $reason = 'Подписка отменена'): int
    {
        return $this->bundledModules()
            ->where('status', 'active')
            ->update([
                'status' => 'suspended',
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);
    }

    public function reactivateBundledModules(): int
    {
        return $this->bundledModules()
            ->where('status', 'suspended')
            ->whereNotNull('cancelled_at')
            ->update([
                'status' => 'active',
                'cancelled_at' => null,
                'cancellation_reason' => null,
                'expires_at' => $this->ends_at,
                'next_billing_date' => $this->next_billing_at,
            ]);
    }
} 