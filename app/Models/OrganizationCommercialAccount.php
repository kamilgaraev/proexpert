<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Billing\CommercialAccountStatus;
use App\Enums\Billing\CommercialOfferType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationCommercialAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'status',
        'offer_type',
        'quote_version',
        'billing_anchor_at',
        'current_period_start_at',
        'current_period_end_at',
        'auto_renew_enabled',
    ];

    protected $casts = [
        'status' => CommercialAccountStatus::class,
        'offer_type' => CommercialOfferType::class,
        'quote_version' => 'integer',
        'billing_anchor_at' => 'datetime',
        'current_period_start_at' => 'datetime',
        'current_period_end_at' => 'datetime',
        'auto_renew_enabled' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function packageSubscriptions(): HasMany
    {
        return $this->hasMany(OrganizationPackageSubscription::class, 'commercial_account_id');
    }
}
