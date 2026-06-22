<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\AccessRecertification;

use Illuminate\Foundation\Http\FormRequest;

final class AccessRecertificationReassignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reviewer_user_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
