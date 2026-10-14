<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;

final class HrChecklistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'lifecycle' => ['required', 'in:onboarding,offboarding'], 'title' => ['required', 'string', 'max:255'],
            'owner_id' => ['required', 'integer'], 'due_date' => ['required', 'date'],
        ];
    }
}
