<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\AccessRecertification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AccessRecertificationItemIndexRequest extends FormRequest
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
            'status' => ['sometimes', 'string', Rule::in([
                'pending',
                'escalated',
                'approved',
                'revoke_requested',
                'revoked',
                'exception_requested',
                'exception_approved',
                'exception_rejected',
            ])],
            'risk_level' => ['sometimes', 'string', Rule::in(['low', 'medium', 'high', 'critical'])],
            'reviewer_user_id' => ['sometimes', 'integer', 'min:1'],
            'subject_user_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
