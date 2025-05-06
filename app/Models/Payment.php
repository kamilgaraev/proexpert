<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'user_subscription_id',
        'payment_gateway_payment_id',
        'payment_gateway_charge_id', // Может быть отдельное поле для ID операции в шлюзе
        'amount',
        'currency',
        'status',
        'description',
        'paid_at',
        'payment_method_details', // Например, тип карты, последние 4 цифры (для mock)
        'gateway_response', // Полный ответ от шлюза для отладки (для mock может быть JSON)
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'payment_method_details' => 'json',
        'gateway_response' => 'json',
    ];

    // Статусы платежа (могут частично пересекаться со статусами YooKassa)
    public const STATUS_PENDING = 'pending'; // Ожидает оплаты / подтверждения
    public const STATUS_WAITING_FOR_CAPTURE = 'waiting_for_capture'; // Для двухстадийных платежей
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled'; // Отменен до оплаты
    public const STATUS_REFUNDED = 'refunded'; // Полностью или частично возвращен
    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(UserSubscription::class, 'user_subscription_id');
    }
} 