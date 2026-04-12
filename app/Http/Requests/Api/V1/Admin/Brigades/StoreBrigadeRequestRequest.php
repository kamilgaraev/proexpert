<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Brigades;

use Illuminate\Foundation\Http\FormRequest;

class StoreBrigadeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'specialization_name' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'team_size_min' => ['nullable', 'integer', 'min:1'],
            'team_size_max' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
