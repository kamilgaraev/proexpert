<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AssistantPaginationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
