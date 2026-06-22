<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\AccessRecertification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AccessRecertificationExceptionDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(['approved', 'rejected'])],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'confirmation' => ['required', 'accepted'],
        ];
    }
}
