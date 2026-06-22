<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\AccessRecertification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AccessRecertificationCampaignIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in(['draft', 'scheduled', 'active', 'completed', 'cancelled'])],
            'type' => ['sometimes', 'string', Rule::in(['periodic', 'event_based', 'risk_based'])],
            'search' => ['sometimes', 'string', 'max:120'],
        ];
    }
}
