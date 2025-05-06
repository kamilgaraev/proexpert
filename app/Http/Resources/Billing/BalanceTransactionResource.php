<?php

namespace App\Http\Resources\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="BalanceTransactionResource",
 *     title="Balance Transaction Resource",
 *     description="Ресурс транзакции по балансу",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="type", type="string", example="credit", description="Тип транзакции (credit/debit)"),
 *     @OA\Property(property="amount_cents", type="integer", example=100000, description="Сумма в минорных единицах"),
 *     @OA\Property(property="amount_formatted", type="string", example="1000.00", description="Сумма, отформатированная для отображения"),
 *     @OA\Property(property="balance_before_cents", type="integer", example=0),
 *     @OA\Property(property="balance_after_cents", type="integer", example=100000),
 *     @OA\Property(property="description", type="string", nullable=true, example="Пополнение баланса"),
 *     @OA\Property(property="payment_id", type="integer", nullable=true, example=123),
 *     @OA\Property(property="user_subscription_id", type="integer", nullable=true, example=456),
 *     @OA\Property(property="meta", type="object", nullable=true, description="Дополнительные данные"),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2025-07-21T15:03:01Z")
 * )
 */
class BalanceTransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'amount_cents' => (int) $this->amount,
            'amount_formatted' => $this->getFormattedAmountAttribute(),
            'balance_before_cents' => (int) $this->balance_before,
            'balance_after_cents' => (int) $this->balance_after,
            'description' => $this->description,
            'payment_id' => $this->payment_id,
            'user_subscription_id' => $this->user_subscription_id,
            'meta' => $this->meta,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
} 