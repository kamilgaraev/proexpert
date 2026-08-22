<?php

declare(strict_types=1);

namespace App\Http\Requests\ConstructionJournal;

final class RejectJournalEntryRequest extends ConstructionJournalFormRequest
{
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:10']];
    }
}
