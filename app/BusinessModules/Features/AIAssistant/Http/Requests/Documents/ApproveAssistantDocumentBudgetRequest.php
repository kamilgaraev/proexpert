<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ApproveAssistantDocumentBudgetRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return ['enabled' => ['required', 'boolean'], 'limit_minor' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'scope' => ['required', Rule::in(['new', 'archive'])], 'confirmed' => ['required', 'accepted']];
    }
}
