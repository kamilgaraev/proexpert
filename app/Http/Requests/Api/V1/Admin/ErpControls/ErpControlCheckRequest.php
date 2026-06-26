<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\ErpControls;

use Illuminate\Foundation\Http\FormRequest;

final class ErpControlCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'operation' => ['required', 'string', 'max:160'],
            'entity_type' => ['nullable', 'string', 'max:160'],
            'entity_id' => ['nullable', 'integer', 'min:1'],
            'scope' => ['nullable', 'array'],
            'scope.organization_id' => ['nullable', 'integer', 'min:1'],
            'scope.project_id' => ['nullable', 'integer', 'min:1'],
            'scope.document_id' => ['nullable'],
            'scope.period_id' => ['nullable', 'integer', 'min:1'],
            'scope.mdm_record_id' => ['nullable', 'integer', 'min:1'],
            'scope.one_c_conflict_id' => ['nullable'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
