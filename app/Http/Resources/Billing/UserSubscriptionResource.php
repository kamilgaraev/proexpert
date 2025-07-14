<?php

namespace App\Http\Resources\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="UserSubscriptionResource",
 *     title="User Subscription Resource",
 *     description="Ресурс подписки пользователя",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="status", type="string", example="active", description="Статус подписки (trial, active, past_due, canceled, expired)"),
 *     @OA\Property(property="trial_ends_at", type="string", format="date-time", nullable=true, example="2025-08-01T12:00:00Z"),
 *     @OA\Property(property="starts_at", type="string", format="date-time", nullable=true, example="2025-07-21T12:00:00Z"),
 *     @OA\Property(property="ends_at", type="string", format="date-time", nullable=true, example="2025-08-20T12:00:00Z"),
 *     @OA\Property(property="next_billing_at", type="string", format="date-time", nullable=true, example="2025-08-20T12:00:00Z"),
 *     @OA\Property(property="canceled_at", type="string", format="date-time", nullable=true, example="2025-08-10T10:00:00Z"),
 *     @OA\Property(property="is_active_now", type="boolean", example=true, description="Является ли подписка валидной на текущий момент (активна или на триале)"),
 *     @OA\Property(property="plan", ref="#/components/schemas/SubscriptionPlanResource"),
 *     @OA\Property(property="payment_gateway_subscription_id", type="string", nullable=true, example="sub_mock_123"),
 *     @OA\Property(property="is_auto_payment_enabled", type="boolean", example=true, description="Включён ли автоплатёж")
 * )
 */
class UserSubscriptionResource extends JsonResource
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
            'status' => $this->status,
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'next_billing_at' => $this->next_billing_at?->toIso8601String(),
            'canceled_at' => $this->canceled_at?->toIso8601String(),
            'is_active_now' => $this->isValid(), // Используем метод isValid() из модели
            'plan' => new SubscriptionPlanResource($this->whenLoaded('plan')),
            'payment_gateway_subscription_id' => $this->payment_gateway_subscription_id,
            'is_auto_payment_enabled' => $this->is_auto_payment_enabled,
        ];
    }
} 