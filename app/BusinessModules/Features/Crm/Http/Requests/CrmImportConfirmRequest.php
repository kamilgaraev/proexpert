<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CrmImportConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decisions' => ['nullable', 'array'],
            'decisions.*.row_id' => ['required_with:decisions', 'uuid'],
            'decisions.*.decision' => ['required_with:decisions', 'string', 'in:create,update,skip'],
            'decisions.*.target_id' => ['nullable', 'uuid'],
        ];
    }
}
