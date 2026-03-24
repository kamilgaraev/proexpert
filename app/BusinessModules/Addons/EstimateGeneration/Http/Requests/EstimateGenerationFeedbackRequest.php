<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EstimateGenerationFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'feedback_type' => ['required', 'string', 'max:100'],
            'section_key' => ['nullable', 'string', 'max:255'],
            'work_item_key' => ['nullable', 'string', 'max:255'],
            'payload' => ['nullable', 'array'],
            'comments' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
