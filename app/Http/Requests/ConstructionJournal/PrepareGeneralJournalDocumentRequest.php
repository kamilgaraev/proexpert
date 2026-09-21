<?php

declare(strict_types=1);

namespace App\Http\Requests\ConstructionJournal;

final class PrepareGeneralJournalDocumentRequest extends ConstructionJournalFormRequest
{
    public function rules(): array
    {
        return [
            'mode' => ['required', 'in:paper_preparation'],
            'expected_revision' => ['required', 'integer', 'min:0'],
            'operation_key' => ['required', 'string', 'max:128'],
            'correction_reason' => ['nullable', 'string', 'max:4000'],
            'profile' => ['present', 'array'],
            'work_details' => ['present', 'array', 'max:5000'],
            'document_version_ids' => ['present', 'array', 'max:500'],
        ];
    }
}
