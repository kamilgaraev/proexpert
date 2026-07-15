<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractorReferralReward extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCRUED = 'accrued';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'contractor_invitation_id',
        'inviting_organization_id',
        'invited_organization_id',
        'commercial_order_id',
        'commercial_payment_id',
        'inviting_balance_transaction_id',
        'invited_balance_transaction_id',
        'status',
        'first_payment_amount',
        'inviting_reward_amount',
        'invited_welcome_amount',
        'currency',
        'eligible_at',
        'invited_welcome_accrued_at',
        'accrued_at',
        'cancelled_at',
        'cancellation_reason',
        'meta',
    ];

    protected $casts = [
        'first_payment_amount' => 'integer',
        'inviting_reward_amount' => 'integer',
        'invited_welcome_amount' => 'integer',
        'eligible_at' => 'datetime',
        'invited_welcome_accrued_at' => 'datetime',
        'accrued_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'meta' => 'array',
    ];

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(ContractorInvitation::class, 'contractor_invitation_id');
    }

    public function invitingOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'inviting_organization_id');
    }

    public function invitedOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'invited_organization_id');
    }

    public function commercialOrder(): BelongsTo
    {
        return $this->belongsTo(CommercialOrder::class, 'commercial_order_id');
    }

    public function commercialPayment(): BelongsTo
    {
        return $this->belongsTo(CommercialPayment::class, 'commercial_payment_id');
    }
}
