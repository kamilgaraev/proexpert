<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;

final class HrChecklistCompletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'completion_reference' => ['required', 'string', 'max:500'],
        ];
    }
}
