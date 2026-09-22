<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreHandoverPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'executive_document_set_id' => ['nullable', 'integer', 'min:1'],
            'documents' => ['present', 'array', 'max:500'],
            'documents.*.title' => ['required', 'string', 'max:255'],
            'documents.*.document_type' => ['required', 'string', 'max:80'],
            'documents.*.is_required' => ['required', 'boolean'],
            'documents.*.status' => ['sometimes', 'in:missing,draft'],
            'documents.*.executive_document_version_id' => ['nullable', 'integer', 'min:1'],
            'documents.*.external_url' => ['prohibited'],
        ];
    }
}
