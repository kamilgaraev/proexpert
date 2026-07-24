<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListChildOrganizationUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'search' => 'nullable|string|max:255',
            'role' => 'nullable|string',
            'status' => 'nullable|in:active,inactive,all',
            'per_page' => 'nullable|integer|min:1|max:50',
        ];
    }
}
