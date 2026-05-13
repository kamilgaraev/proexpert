<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReserveAssetRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('expires_at') && ! $this->filled('expires_hours')) {
            try {
                $expiresAt = Carbon::parse((string) $this->input('expires_at'));
                $hours = max(1, (int) ceil(now()->diffInMinutes($expiresAt, false) / 60));

                $this->merge([
                    'expires_hours' => $hours,
                ]);
            } catch (\Throwable) {
            }
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->user()?->current_organization_id;

        return [
            'warehouse_id' => [
                'required',
                Rule::exists('organization_warehouses', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true),
            ],
            'material_id' => [
                'required',
                Rule::exists('materials', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true),
            ],
            'quantity' => 'required|numeric|min:0.001',
            'project_id' => [
                'nullable',
                Rule::exists('projects', 'id')->where('organization_id', $organizationId),
            ],
            'expires_hours' => 'nullable|integer|min:1|max:168',
            'reason' => 'nullable|string',
        ];
    }
}
