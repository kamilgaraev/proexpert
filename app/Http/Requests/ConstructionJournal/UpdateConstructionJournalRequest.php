<?php

declare(strict_types=1);

namespace App\Http\Requests\ConstructionJournal;

use Illuminate\Foundation\Http\FormRequest;

class UpdateConstructionJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'journal_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'contract_id' => ['sometimes', 'required', 'integer', 'exists:contracts,id'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date'],
            'status' => ['prohibited'],
        ];
    }
}
