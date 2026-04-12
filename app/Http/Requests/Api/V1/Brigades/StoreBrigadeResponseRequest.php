<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Brigades;

use Illuminate\Foundation\Http\FormRequest;

class StoreBrigadeResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'request_id' => ['required', 'integer', 'exists:brigade_requests,id'],
            'cover_message' => ['nullable', 'string'],
        ];
    }
}
