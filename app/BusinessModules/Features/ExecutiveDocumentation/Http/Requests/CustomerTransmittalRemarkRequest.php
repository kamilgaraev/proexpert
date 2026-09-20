<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CustomerTransmittalRemarkRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'operation_key' => ['required', 'string', 'max:128'],
            'transmittal_id' => ['required', 'integer', 'min:1'],
            'version_id' => ['required', 'integer', 'min:1'],
            'body' => ['required', 'string', 'max:5000'],
            'severity' => ['nullable', Rule::in(['minor', 'major', 'critical'])],
        ];
    }
}
