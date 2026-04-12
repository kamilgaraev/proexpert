<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Brigades;

use Illuminate\Foundation\Http\FormRequest;

class StoreBrigadeInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'brigade_id' => ['required', 'integer', 'exists:brigades,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'message' => ['nullable', 'string'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }
}
