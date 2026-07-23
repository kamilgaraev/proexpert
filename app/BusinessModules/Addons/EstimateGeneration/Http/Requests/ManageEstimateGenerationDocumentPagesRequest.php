<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ManageEstimateGenerationDocumentPagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'state_version' => ['required', 'integer', 'min:0'],
            'page_numbers' => ['required', 'array', 'min:1', 'max:200'],
            'page_numbers.*' => ['required', 'integer', 'min:1', 'max:100000', 'distinct'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
