<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Models;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BudgetLimitReservation extends Model
{
    use HasUuids;

    public const STATUS_RESERVED = 'reserved';
    public const STATUS_RELEASED = 'released';
    public const STATUS_CONVERTED = 'converted';

    protected $fillable = [
        'uuid',
        'organization_id',
        'payment_document_id',
        'budget_limit_check_id',
        'budget_period_id',
        'budget_article_id',
        'responsibility_center_id',
        'project_id',
        'contract_id',
        'counterparty_id',
        'period_month',
        'currency',
        'amount',
        'status',
        'reserved_at',
        'released_at',
        'converted_at',
        'release_reason',
        'created_by_user_id',
        'metadata',
    ];

    protected $casts = [
        'period_month' => 'date',
        'amount' => 'decimal:2',
        'reserved_at' => 'datetime',
        'released_at' => 'datetime',
        'converted_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function paymentDocument(): BelongsTo
    {
        return $this->belongsTo(PaymentDocument::class, 'payment_document_id');
    }

    public function budgetLimitCheck(): BelongsTo
    {
        return $this->belongsTo(BudgetLimitCheck::class, 'budget_limit_check_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
