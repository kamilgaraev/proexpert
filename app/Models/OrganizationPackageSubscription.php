<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Billing\PackageAccessSource;
use App\Enums\Billing\PackageSubscriptionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationPackageSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'commercial_account_id',
        'package_slug',
        'status',
        'access_source',
        'price_paid',
        'current_period_start_at',
        'current_period_end_at',
        'trial_started_at',
        'trial_ends_at',
        'cancel_at',
        'canceled_at',
        'source_order_id',
    ];

    protected $casts = [
        'status' => PackageSubscriptionStatus::class,
        'access_source' => PackageAccessSource::class,
        'price_paid' => 'decimal:2',
        'current_period_start_at' => 'datetime',
        'current_period_end_at' => 'datetime',
        'trial_started_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'cancel_at' => 'datetime',
        'canceled_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function commercialAccount(): BelongsTo
    {
        return $this->belongsTo(OrganizationCommercialAccount::class, 'commercial_account_id');
    }

    public function sourceOrder(): BelongsTo
    {
        return $this->belongsTo(CommercialOrder::class, 'source_order_id');
    }

    public function isActive(): bool
    {
        if ($this->status === PackageSubscriptionStatus::Trialing) {
            return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
        }

        if (! in_array($this->status?->value, PackageSubscriptionStatus::periodAccessValues(), true)) {
            return false;
        }

        if ($this->access_source === PackageAccessSource::Corporate) {
            return $this->current_period_end_at === null || $this->current_period_end_at->isFuture();
        }

        return $this->current_period_end_at !== null && $this->current_period_end_at->isFuture();
    }

    public function isExpired(): bool
    {
        return ! $this->isActive();
    }

    public function scopeActive($query)
    {
        return $query->where(function ($query): void {
            $query->where(function ($trial): void {
                $trial->where('status', PackageSubscriptionStatus::Trialing->value)
                    ->whereNotNull('trial_ends_at')
                    ->where('trial_ends_at', '>', now());
            })->orWhere(function ($period): void {
                $period->whereIn('status', PackageSubscriptionStatus::periodAccessValues())
                    ->where(function ($access): void {
                        $access->where(function ($corporate): void {
                            $corporate->where('access_source', PackageAccessSource::Corporate->value)
                                ->where(function ($dates): void {
                                    $dates->whereNull('current_period_end_at')
                                        ->orWhere('current_period_end_at', '>', now());
                                });
                        })->orWhere(function ($paid): void {
                            $paid->where('access_source', '!=', PackageAccessSource::Corporate->value)
                                ->whereNotNull('current_period_end_at')
                                ->where('current_period_end_at', '>', now());
                        });
                    });
            });
        });
    }
}
