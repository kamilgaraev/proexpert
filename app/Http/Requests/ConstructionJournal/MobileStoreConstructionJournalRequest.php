<?php

declare(strict_types=1);

namespace App\Http\Requests\ConstructionJournal;

class MobileStoreConstructionJournalRequest extends StoreConstructionJournalRequest
{
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            ...parent::rules(),
        ];
    }
}
