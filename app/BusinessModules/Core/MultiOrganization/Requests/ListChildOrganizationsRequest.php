<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListChildOrganizationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'search' => 'nullable|string|max:255',
            'status' => 'nullable|in:active,inactive,all',
            'sort_by' => 'nullable|in:name,created_at,users_count,projects_count',
            'sort_direction' => 'nullable|in:asc,desc',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }
}
