<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class TransmitExecutiveDocumentSetRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'transmittal_number' => ['required', 'string', 'max:80'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'operation_key' => ['required', 'string', 'max:128'],
            'expected_versions' => ['required', 'array', 'min:1', 'max:1000'],
            'expected_versions.*.document_id' => ['required', 'integer', 'min:1', 'distinct'],
            'expected_versions.*.version_id' => ['required', 'integer', 'min:1', 'distinct'],
            'paper_originals' => ['sometimes', 'array', 'max:1000'],
            'paper_originals.*.document_id' => ['required', 'integer', 'min:1', 'distinct'],
            'paper_originals.*.copies_count' => ['required', 'integer', 'min:1', 'max:50'],
        ];
    }
}
