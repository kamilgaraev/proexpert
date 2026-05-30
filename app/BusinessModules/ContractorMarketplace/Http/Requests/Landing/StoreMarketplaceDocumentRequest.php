<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Http\Requests\Landing;

use Illuminate\Foundation\Http\FormRequest;

class StoreMarketplaceDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organizationId = $this->attributes->get('current_organization_id') ?? $this->user()?->current_organization_id;

        return $this->user() !== null
            && $organizationId !== null
            && $this->user()->belongsToOrganization((int) $organizationId);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:255'],
            'document' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx'],
        ];
    }

    public function messages(): array
    {
        return [
            'document.required' => trans_message('contractor_marketplace.document_file_required'),
            'document.file' => trans_message('contractor_marketplace.document_file_invalid'),
            'document.max' => trans_message('contractor_marketplace.document_file_too_large'),
            'document.mimes' => trans_message('contractor_marketplace.document_file_type_invalid'),
        ];
    }
}
