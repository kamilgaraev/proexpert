<?php

namespace App\BusinessModules\Core\Payments\Models;

use App\Models\Contractor;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CounterpartyAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'counterparty_organization_id',
        'counterparty_contractor_id',
        'receivable_balance',
        'payable_balance',
        'net_balance',
        'credit_limit',
        'payment_terms_days',
        'is_active',
        'is_blocked',
        'block_reason',
        'total_invoices_count',
        'overdue_invoices_count',
        'avg_payment_delay_days',
        'last_transaction_at',
    ];

    protected $casts = [
        'receivable_balance' => 'decimal:2',
        'payable_balance' => 'decimal:2',
        'net_balance' => 'decimal:2',
        'credit_limit' => 'decimal:2',
        'is_active' => 'boolean',
        'is_blocked' => 'boolean',
        'last_transaction_at' => 'datetime',
    ];

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function counterpartyOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'counterparty_organization_id');
    }

    public function counterpartyContractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class, 'counterparty_contractor_id');
    }

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopeForOrganization($query, int $orgId)
    {
        return $query->where('organization_id', $orgId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeBlocked($query)
    {
        return $query->where('is_blocked', true);
    }

    public function scopeWithOverdue($query)
    {
        return $query->where('overdue_invoices_count', '>', 0);
    }

    // ==========================================
    // HELPERS
    // ==========================================

    /**
     * Пересчитать чистый баланс
     */
    public function recalculateNetBalance(): void
    {
        $this->net_balance = $this->receivable_balance - $this->payable_balance;
        $this->save();
    }

    /**
     * Проверить превышение кредитного лимита
     */
    public function isCreditLimitExceeded(): bool
    {
        if ($this->credit_limit === null) {
            return false;
        }

        return $this->payable_balance > $this->credit_limit;
    }

    /**
     * Получить доступный кредит
     */
    public function getAvailableCredit(): ?float
    {
        if ($this->credit_limit === null) {
            return null;
        }

        return max(0, $this->credit_limit - $this->payable_balance);
    }

    /**
     * Есть ли просроченные счета
     */
    public function hasOverdueInvoices(): bool
    {
        return $this->overdue_invoices_count > 0;
    }
}

