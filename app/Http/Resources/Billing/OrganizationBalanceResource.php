<?php

namespace App\Http\Resources\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="OrganizationBalanceResource",
 *     title="Organization Balance Resource",
 *     description="Ресурс баланса организации",
 *     @OA\Property(property="organization_id", type="integer", example=1),
 *     @OA\Property(property="balance_cents", type="integer", example=50000, description="Баланс в минорных единицах (копейках)"),
 *     @OA\Property(property="balance_formatted", type="string", example="500.00", description="Баланс в основной валюте, отформатированный для отображения"),
 *     @OA\Property(property="currency", type="string", example="RUB")
 * )
 */
class OrganizationBalanceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'organization_id' => $this->organization_id,
            'balance_cents' => (int) $this->balance, // Уже integer, но для ясности
            'balance_formatted' => $this->getFormattedBalanceAttribute(), // Используем аксессор
            'currency' => $this->currency,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
} 