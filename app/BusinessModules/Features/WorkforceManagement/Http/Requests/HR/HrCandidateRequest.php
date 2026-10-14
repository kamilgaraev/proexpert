<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;

final class HrCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'vacancy_id' => ['required', 'integer'], 'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:80'], 'owner_id' => ['required', 'integer'],
        ];
    }
}
