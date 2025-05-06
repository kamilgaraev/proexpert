<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BalanceTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_balance_id',
        'payment_id', // Ссылка на платеж (если это пополнение)
        'user_subscription_id', // Ссылка на подписку (если это списание за подписку)
        'type', // credit (пополнение), debit (списание)
        'amount', // Сумма транзакции в минорных единицах (копейках)
        'balance_before', // Баланс до транзакции
        'balance_after', // Баланс после транзакции
        'description', // Описание (например, "Пополнение баланса", "Оплата подписки Pro")
        'meta', // Дополнительные метаданные в JSON
    ];

    protected $casts = [
        'amount' => 'integer',
        'balance_before' => 'integer',
        'balance_after' => 'integer',
        'meta' => 'json',
    ];

    public const TYPE_CREDIT = 'credit';
    public const TYPE_DEBIT = 'debit';

    public function organizationBalance(): BelongsTo
    {
        return $this->belongsTo(OrganizationBalance::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class)->withDefault(); // withDefault если платеж может отсутствовать
    }

    public function userSubscription(): BelongsTo
    {
        return $this->belongsTo(UserSubscription::class)->withDefault(); // withDefault если подписка может отсутствовать
    }

    /**
     * Форматированная сумма транзакции для отображения (в рублях).
     */
    public function getFormattedAmountAttribute(): string
    {
        return number_format($this->amount / 100, 2, '.', ' ');
    }
} 