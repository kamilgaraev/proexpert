<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\ImmutableAudit;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ImmutableAuditEventIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'domain' => ['sometimes', 'string', Rule::in(['payments', 'budgeting', 'mdm', 'rbac', 'one_c_exchange', 'warehouse', 'crm', 'period_close', 'procurement', 'sod'])],
            'event_type' => ['sometimes', 'string', 'max:160'],
            'action' => ['sometimes', 'string', 'max:64'],
            'result' => ['sometimes', 'string', 'max:64'],
            'severity' => ['sometimes', 'string', 'max:64'],
            'integrity_status' => ['sometimes', 'string', Rule::in(['pending', 'sealed', 'verified', 'broken', 'archived'])],
            'actor_user_id' => ['sometimes', 'integer'],
            'project_id' => ['sometimes', 'integer'],
            'subject_type' => ['sometimes', 'string', 'max:160'],
            'subject_id' => ['sometimes', 'string', 'max:120'],
            'correlation_id' => ['sometimes', 'string', 'max:120'],
            'source' => ['sometimes', 'string', 'max:120'],
            'chain_scope' => ['sometimes', 'string', 'max:120'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
        ];
    }
}
