<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SubmitWorkReworkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:4000'],
            'evidence_file_ids' => ['present', 'array', 'max:30'],
            'evidence_file_ids.*' => ['integer', 'min:1', 'distinct'],
            'expected_revision' => ['required', 'integer', 'min:1'],
            'operation_key' => ['required', 'string', 'max:160'],
        ];
    }
}
