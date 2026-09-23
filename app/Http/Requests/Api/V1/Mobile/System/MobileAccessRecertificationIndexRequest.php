<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mobile\System;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class MobileAccessRecertificationIndexRequest extends FormRequest
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
                'draft', 'scheduled', 'active', 'completed', 'cancelled',
                'pending', 'escalated', 'approved', 'revoke_requested', 'revoked',
                'exception_requested', 'exception_approved', 'exception_rejected',
            ])],
            'type' => ['sometimes', 'string', Rule::in(['periodic', 'event_based', 'risk_based'])],
            'risk_level' => ['sometimes', 'string', Rule::in(['low', 'medium', 'high', 'critical'])],
            'search' => ['sometimes', 'string', 'max:120'],
        ];
    }
}
