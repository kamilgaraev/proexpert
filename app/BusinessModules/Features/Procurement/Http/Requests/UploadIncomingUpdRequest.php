<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UploadIncomingUpdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && (int) $this->attributes->get('current_organization_id') > 0;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'mimes:xml'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => trans_message('procurement.upd.upload.file_required'),
            'file.file' => trans_message('procurement.upd.upload.file_invalid'),
            'file.max' => trans_message('procurement.upd.upload.file_too_large'),
            'file.mimes' => trans_message('procurement.upd.upload.file_type_invalid'),
        ];
    }
}
