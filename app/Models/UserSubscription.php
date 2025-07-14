<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class UserSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'subscription_plan_id',
        'status',
        'trial_ends_at',
        'starts_at',
        'ends_at',
        'next_billing_at',
        'payment_gateway_subscription_id',
        'payment_gateway_customer_id',
        'canceled_at',
        'payment_failure_notified_at',
        'is_auto_payment_enabled',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'next_billing_at' => 'datetime',
        'canceled_at' => 'datetime',
        'payment_failure_notified_at' => 'datetime',
        'is_auto_payment_enabled' => 'boolean',
    ];

    // Возможные статусы подписки
    public const STATUS_TRIAL = 'trial';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PENDING_PAYMENT = 'pending_payment'; // Ожидает первоначальной оплаты для активации
    public const STATUS_PAST_DUE = 'past_due'; // Просрочена оплата
    public const STATUS_CANCELED = 'canceled'; // Отменена пользователем, может быть активна до конца периода
    public const STATUS_EXPIRED = 'expired'; // Истекла и не была продлена
    public const STATUS_INCOMPLETE = 'incomplete'; // Платеж не завершен (например, 3DSecure)


    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE || $this->isOnTrial();
    }

    public function isOnTrial(): bool
    {
        return $this->status === self::STATUS_TRIAL && $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    public function isCanceled(): bool
    {
        return $this->status === self::STATUS_CANCELED || !is_null($this->canceled_at);
    }

    /**
     * Проверяет, активна ли подписка на данный момент (включая триал и активный оплаченный период, 
     * даже если она отменена, но еще не истекла).
     */
    public function isValid(): bool
    {
        if ($this->isOnTrial()) {
            return true;
        }
        // Если активна и дата окончания в будущем или не установлена (бессрочная, хотя у нас по планам есть duration)
        return $this->status === self::STATUS_ACTIVE && ($this->ends_at && $this->ends_at->isFuture());
    }
    
    /**
     * Определяет, должна ли подписка быть активной (не отменена и не истекла).
     * Используется для проверки возможности возобновления и т.д.
     */
    public function shouldBeActive(): bool
    {
        return $this->ends_at === null || $this->ends_at->isFuture();
    }
} 