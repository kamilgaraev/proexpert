<?php

namespace App\Http\Resources\Billing;

use App\Http\Resources\ModelJsonResource;
use App\Models\BalanceTransaction;
use Illuminate\Http\Request;

/**
 * @OA\Schema(
 *     schema="BalanceTransactionResource",
 *     title="Balance Transaction Resource",
 *     description="Ресурс транзакции по балансу",
 *
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="type", type="string", example="credit", description="Тип транзакции (credit/debit)"),
 *     @OA\Property(property="amount_cents", type="integer", example=100000, description="Сумма в минорных единицах"),
 *     @OA\Property(property="amount_formatted", type="string", example="1000.00", description="Сумма, отформатированная для отображения"),
 *     @OA\Property(property="balance_before_cents", type="integer", example=0),
 *     @OA\Property(property="balance_after_cents", type="integer", example=100000),
 *     @OA\Property(property="description", type="string", nullable=true, example="Пополнение баланса"),
 *     @OA\Property(property="payment_id", type="integer", nullable=true, example=123),
 *     @OA\Property(property="meta", type="object", nullable=true, description="Дополнительные данные"),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2025-07-21T15:03:01Z")
 * )
 */
class BalanceTransactionResource extends ModelJsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $transaction = $this->typedResource(BalanceTransaction::class);

        return [
            'id' => $this->id,
            'type' => $this->type,
            'amount_cents' => (int) $this->amount,
            'amount_formatted' => $transaction->getFormattedAmountAttribute(),
            'balance_before_cents' => (int) $this->balance_before,
            'balance_after_cents' => (int) $this->balance_after,
            'description' => $this->description,
            'payment_id' => $this->payment_id,
            'meta' => $this->meta,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
