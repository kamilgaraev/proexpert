<?php

namespace App\BusinessModules\Core\Payments\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class PaymentSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'installment_number',
        'due_date',
        'amount',
        'status',
        'paid_at',
        'payment_transaction_id',
        'notes',
    ];

    protected $casts = [
        'due_date' => 'date',
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopeForInvoice($query, int $invoiceId)
    {
        return $query->where('invoice_id', $invoiceId);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'pending')
            ->where('due_date', '<', Carbon::now());
    }

    public function scopeUpcoming($query, int $days = 7)
    {
        return $query->where('status', 'pending')
            ->whereBetween('due_date', [Carbon::now(), Carbon::now()->addDays($days)]);
    }

    // ==========================================
    // HELPERS
    // ==========================================

    /**
     * Оплачен ли платёж
     */
    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /**
     * Просрочен ли платёж
     */
    public function isOverdue(): bool
    {
        return $this->status === 'pending' && $this->due_date < Carbon::now();
    }

    /**
     * Количество дней до срока оплаты (или просрочки)
     */
    public function getDaysUntilDue(): int
    {
        return Carbon::now()->diffInDays($this->due_date, false);
    }
}

