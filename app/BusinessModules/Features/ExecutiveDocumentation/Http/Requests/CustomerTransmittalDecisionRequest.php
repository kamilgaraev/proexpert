<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CustomerTransmittalDecisionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'operation_key' => ['required', 'string', 'max:128'],
            'expected_manifest_hash' => ['required', 'string', 'size:64'],
            'comment' => [$this->route('action') === 'return' ? 'required' : 'nullable', 'string', 'max:5000'],
        ];
    }
}
