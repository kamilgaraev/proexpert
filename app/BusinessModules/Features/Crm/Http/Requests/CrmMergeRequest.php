<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CrmMergeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entity_type' => ['required', 'string', Rule::in(['companies', 'contacts'])],
            'master_id' => ['required', 'uuid'],
            'duplicate_id' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
