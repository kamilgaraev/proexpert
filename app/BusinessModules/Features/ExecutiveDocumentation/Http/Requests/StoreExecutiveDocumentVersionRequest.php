<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

final class StoreExecutiveDocumentVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'expected_version_id' => ['required', 'integer', 'min:0'],
            'version_number' => ['required', 'string', 'max:40'],
            'file' => ['required', File::types(['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'webp'])->min(1)->max(25 * 1024)],
            'file_kind' => ['nullable', 'in:copy,paper_scan,electronic_original'],
            'signature_file' => ['nullable', 'file', 'max:25600'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'uploaded_at' => ['nullable', 'date'],
            'profile_snapshot' => ['nullable', 'array'],
            'profile_data' => ['nullable', 'array'],
            'basis_snapshot' => ['nullable', 'array:project_id,completed_work_id,journal_entry_id'],
            'basis_snapshot.project_id' => ['nullable', 'integer', 'min:1'],
            'basis_snapshot.completed_work_id' => ['nullable', 'integer', 'min:1'],
            'basis_snapshot.journal_entry_id' => ['nullable', 'integer', 'min:1'],
            'metadata' => ['nullable', 'array'],
            'operation_key' => ['required', 'string', 'max:128'],
        ];
    }
}
