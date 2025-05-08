<?php

namespace App\Http\Resources\User;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Преобразовать ресурс в массив.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'position' => $this->position,
            'avatar_url' => $this->avatar_url,
            'employee_id' => $this->employee_id,
            'external_code' => $this->external_code,
            'current_balance' => (float) $this->current_balance,
            'total_issued' => (float) $this->total_issued,
            'total_reported' => (float) $this->total_reported,
            'has_overdue_balance' => (bool) $this->has_overdue_balance,
            'last_transaction_at' => $this->last_transaction_at ? $this->last_transaction_at->format('Y-m-d H:i:s') : null,
        ];
    }
} 