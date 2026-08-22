<?php

declare(strict_types=1);

namespace App\Http\Requests\ConstructionJournal;

final class ApproveJournalEntryRequest extends ConstructionJournalFormRequest
{
    public function rules(): array
    {
        return [
            'override' => ['nullable', 'array'],
            'override.enabled' => ['nullable', 'boolean'],
            'override.reason' => ['nullable', 'string', 'max:2000'],
            'override.target' => ['nullable', 'in:schedule_missing,contract_missing,over_coverage,manual_act_line'],
        ];
    }
}
