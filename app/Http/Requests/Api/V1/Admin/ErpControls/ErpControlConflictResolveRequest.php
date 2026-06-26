<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\ErpControls;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ErpControlConflictResolveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(['accepted_risk', 'false_positive', 'acknowledged', 'request_review'])],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'second_approver_user_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
