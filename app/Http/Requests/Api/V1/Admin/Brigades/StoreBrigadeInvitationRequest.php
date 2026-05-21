<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Brigades;

use App\BusinessModules\Contractors\Brigades\Support\BrigadeStatuses;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

use function trans_message;

class StoreBrigadeInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->attributes->get('current_organization_id') ?? $this->user()?->current_organization_id;

        return [
            'brigade_id' => [
                'required',
                'integer',
                Rule::exists('brigades', 'id')
                    ->where('verification_status', BrigadeStatuses::PROFILE_APPROVED),
            ],
            'project_id' => [
                'nullable',
                'integer',
                Rule::exists('projects', 'id')->where('organization_id', $organizationId),
            ],
            'message' => ['nullable', 'string'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }

    public function messages(): array
    {
        return [
            'brigade_id.exists' => trans_message('brigades.invitation_brigade_not_available'),
        ];
    }
}
