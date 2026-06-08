<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Models;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BudgetLimitCheck extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid',
        'organization_id',
        'payment_document_id',
        'payment_transaction_id',
        'operation_type',
        'operation_id',
        'budget_period_id',
        'budget_article_id',
        'responsibility_center_id',
        'project_id',
        'contract_id',
        'counterparty_id',
        'period_month',
        'currency',
        'requested_amount',
        'status',
        'decision',
        'message',
        'required_permission',
        'accepted',
        'checked_by_user_id',
        'overridden_by_user_id',
        'override_reason',
        'sources',
        'summary',
        'dimensions',
        'audit_trail',
    ];

    protected $casts = [
        'period_month' => 'date',
        'requested_amount' => 'decimal:2',
        'accepted' => 'boolean',
        'sources' => 'array',
        'summary' => 'array',
        'dimensions' => 'array',
        'audit_trail' => 'array',
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

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by_user_id');
    }

    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by_user_id');
    }
}
