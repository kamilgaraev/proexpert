<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mobile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class MobileBrigadeRequestStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $organizationId = $this->attributes->get('current_organization_id') ?? $this->user()?->current_organization_id;

        return [
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('organization_id', $organizationId)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'specialization_name' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'team_size_min' => ['nullable', 'integer', 'min:1', 'max:500'],
            'team_size_max' => ['nullable', 'integer', 'min:1', 'max:500', 'gte:team_size_min'],
        ];
    }
}
