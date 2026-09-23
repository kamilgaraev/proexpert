<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mobile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreMobileCrmActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['note', 'next_contact'])],
            'target_type' => ['required', Rule::in(['company', 'contact', 'lead', 'deal'])],
            'target_id' => ['required', 'uuid'],
            'subject' => ['required', 'string', 'max:500'],
            'body' => ['nullable', 'string', 'max:4000'],
            'due_at' => ['required_if:kind,next_contact', 'nullable', 'date'],
        ];
    }
}
