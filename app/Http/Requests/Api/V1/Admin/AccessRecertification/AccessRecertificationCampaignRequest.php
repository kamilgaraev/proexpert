<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\AccessRecertification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AccessRecertificationCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'type' => ['sometimes', 'string', Rule::in(['periodic', 'event_based', 'risk_based'])],
            'status' => ['sometimes', 'string', Rule::in(['draft', 'scheduled'])],
            'risk_mode' => ['sometimes', 'string', Rule::in(['all', 'risk_based', 'high_risk_only'])],
            'scope' => ['sometimes', 'array'],
            'scope.role_slugs' => ['sometimes', 'array'],
            'scope.role_slugs.*' => ['string', 'max:120'],
            'scope.user_ids' => ['sometimes', 'array'],
            'scope.user_ids.*' => ['integer', 'min:1'],
            'scope.risk_levels' => ['sometimes', 'array'],
            'scope.risk_levels.*' => ['string', Rule::in(['low', 'medium', 'high', 'critical'])],
            'owner_user_id' => ['required', 'integer', 'exists:users,id'],
            'escalation_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'due_at' => ['required', 'date', 'after:today'],
        ];
    }
}
