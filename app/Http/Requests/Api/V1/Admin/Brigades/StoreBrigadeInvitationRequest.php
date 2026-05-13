<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Brigades;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'brigade_id' => ['required', 'integer', 'exists:brigades,id'],
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
}
