<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\ImmutableAudit;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ImmutableAuditIntegrityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'domain' => ['sometimes', 'string', Rule::in(['payments', 'budgeting', 'mdm', 'rbac', 'one_c_exchange', 'warehouse', 'crm', 'period_close', 'procurement', 'sod'])],
            'chain_scope' => ['sometimes', 'string', 'max:120'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
            'integrity_status' => ['sometimes', 'string', Rule::in(['pending', 'sealed', 'verified', 'broken', 'archived'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
