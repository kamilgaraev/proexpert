<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mobile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class MobileFieldFileUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'min:1'],
            'record_type' => ['required', 'in:project,completed_work,construction_journal_entry'],
            'record_id' => ['required', 'integer', 'min:1'],
            'file_type' => ['required', 'in:photo,document'],
            'file' => ['required', 'file', 'max:20480', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $file = $this->file('file');
            $type = $this->input('file_type');
            if ($file === null || ! $file->isValid()) {
                return;
            }

            $mime = $file->getMimeType();
            $isImage = is_string($mime) && str_starts_with($mime, 'image/');
            $isAllowedDocument = in_array($mime, [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ], true);

            if (($type === 'photo' && ! $isImage) || ($type === 'document' && ! ($isImage || $isAllowedDocument))) {
                $validator->errors()->add('file', trans_message('mobile_companions.errors.validation_failed'));
            }
        });
    }
}
