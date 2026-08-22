<?php

declare(strict_types=1);

namespace App\Http\Requests\ConstructionJournal;

final class UpdateJournalEntryRequest extends StoreJournalEntryRequest
{
    public function rules(): array
    {
        return $this->entryRules(true);
    }
}
