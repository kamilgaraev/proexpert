<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdminAuthUserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'phone' => $this->phone,
            'position' => $this->position,
            'avatar_url' => $this->avatar_url,
            'is_active' => $this->is_active,
            'current_organization_id' => $this->current_organization_id,
            'settings' => $this->settings,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'last_login_ip' => $this->last_login_ip,
            'has_completed_onboarding' => $this->has_completed_onboarding,
            'external_code' => $this->external_code,
            'employee_id' => $this->employee_id,
            'accounting_account' => $this->accounting_account,
            'current_balance' => $this->current_balance,
            'total_issued' => $this->total_issued,
            'total_reported' => $this->total_reported,
            'last_transaction_at' => $this->last_transaction_at?->toIso8601String(),
            'has_overdue_balance' => $this->has_overdue_balance,
            'accounting_data' => $this->accounting_data,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
