<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateChildOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'inn' => 'nullable|string|max:12',
            'kpp' => 'nullable|string|max:9',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'is_active' => 'sometimes|boolean',
            'settings' => 'sometimes|array',
        ];
    }
}
