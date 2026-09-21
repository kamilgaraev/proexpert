<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ApproveHandoverPackageDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'executive_document_version_id' => ['nullable', 'integer', 'min:1'],
            'external_url' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
