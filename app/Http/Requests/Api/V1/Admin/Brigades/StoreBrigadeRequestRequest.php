<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Brigades;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBrigadeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->attributes->get('current_organization_id') ?? $this->user()?->current_organization_id;

        return [
            'project_id' => [
                'nullable',
                'integer',
                Rule::exists('projects', 'id')->where('organization_id', $organizationId),
            ],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'specialization_name' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'team_size_min' => ['nullable', 'integer', 'min:1'],
            'team_size_max' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
