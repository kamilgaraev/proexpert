<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\ActReport;

use Illuminate\Foundation\Http\FormRequest;

final class AnnulActReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:128'],
        ];
    }
}
