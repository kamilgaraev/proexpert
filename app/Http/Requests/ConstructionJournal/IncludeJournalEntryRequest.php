<?php

declare(strict_types=1);

namespace App\Http\Requests\ConstructionJournal;

final class IncludeJournalEntryRequest extends ConstructionJournalFormRequest
{
    public function rules(): array
    {
        return [
            'target_journal_id' => 'required|integer|min:1',
            'entry_date' => 'sometimes|date',
            'comment' => 'nullable|string|max:4000',
        ];
    }
}
