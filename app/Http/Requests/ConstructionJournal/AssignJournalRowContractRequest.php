<?php

declare(strict_types=1);

namespace App\Http\Requests\ConstructionJournal;

final class AssignJournalRowContractRequest extends ConstructionJournalFormRequest
{
    public function rules(): array
    {
        return [
            'contract_id' => ['present', 'nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
