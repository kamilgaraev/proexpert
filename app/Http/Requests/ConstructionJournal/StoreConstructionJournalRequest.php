<?php

declare(strict_types=1);

namespace App\Http\Requests\ConstructionJournal;

class StoreConstructionJournalRequest extends ConstructionJournalFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'journal_number' => ['nullable', 'string', 'max:50'],
            'contract_id' => ['required', 'integer', 'exists:contracts,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['prohibited'],
        ];
    }
}
