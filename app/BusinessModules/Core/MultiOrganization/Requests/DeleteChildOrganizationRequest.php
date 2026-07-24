<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeleteChildOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'transfer_data_to' => 'nullable|integer|exists:organizations,id',
            'confirm_deletion' => 'required|boolean|accepted',
        ];
    }
}
