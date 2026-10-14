<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;

final class HrDevelopmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'record_type' => ['required', 'in:training,qualification,assessment,goal,review'], 'title' => ['required', 'string', 'max:255'],
            'owner_id' => ['required', 'integer'], 'effective_date' => ['required', 'date'], 'valid_until' => ['nullable', 'date', 'after_or_equal:effective_date'],
            'result' => ['nullable', 'string', 'max:1000'], 'source_file_id' => ['nullable', 'integer'], 'supersedes_id' => ['nullable', 'integer'],
        ];
    }
}
