<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Billing\CommercialOfferType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CommercialContourChange extends Model
{
    protected $fillable = [
        'public_id', 'organization_id', 'commercial_account_id', 'user_id', 'status',
        'offer_type', 'quote_version', 'target_package_slugs', 'current_package_slugs',
        'apply_at', 'client_idempotency_key', 'commercial_order_id', 'applied_at',
    ];

    protected $casts = [
        'offer_type' => CommercialOfferType::class,
        'quote_version' => 'integer',
        'target_package_slugs' => 'array',
        'current_package_slugs' => 'array',
        'apply_at' => 'immutable_datetime',
        'applied_at' => 'immutable_datetime',
    ];

    public function commercialAccount(): BelongsTo
    {
        return $this->belongsTo(OrganizationCommercialAccount::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(CommercialOrder::class, 'commercial_order_id');
    }
}
