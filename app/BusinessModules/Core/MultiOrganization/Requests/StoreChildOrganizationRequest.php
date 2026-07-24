<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreChildOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'group_id' => 'required|integer|exists:organization_groups,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'inn' => 'nullable|string|max:12',
            'kpp' => 'nullable|string|max:9',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'owner.name' => 'required|string|max:255',
            'owner.email' => 'required|email|max:255',
            'owner.password' => 'nullable|string|min:8',
        ];
    }
}
