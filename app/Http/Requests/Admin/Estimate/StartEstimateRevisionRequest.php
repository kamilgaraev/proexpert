<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Estimate;

use Illuminate\Foundation\Http\FormRequest;

final class StartEstimateRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:128'],
        ];
    }

    public function messages(): array
    {
        return [
            'idempotency_key.required' => trans_message('estimate.idempotency_key_required'),
        ];
    }
}
