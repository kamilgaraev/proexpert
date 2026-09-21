<?php

declare(strict_types=1);

namespace App\Services\ConstructionJournal;

use App\Exceptions\BusinessLogicException;
use App\Services\LegalArchive\CanonicalJson;
use Illuminate\Support\Facades\Validator;

final class GeneralJournalDocumentInput
{
    public function validate(array $data): array
    {
        $rules = [
            'mode' => ['required', 'in:paper_preparation'],
            'expected_revision' => ['required', 'integer', 'min:0'],
            'operation_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'correction_reason' => ['nullable', 'string', 'max:4000'],
            'profile' => ['present', 'array:header,representatives,title_changes,sections'],
            'profile.header' => ['sometimes', 'array:'.implode(',', array_keys(GeneralJournalDocumentDefinition::headerFields()))],
            'profile.header.*' => ['nullable', 'string', 'max:10000'],
            'profile.representatives' => ['sometimes', 'array:'.implode(',', array_keys(GeneralJournalDocumentDefinition::representativeGroups()))],
            'profile.representatives.*' => ['array', 'max:100'],
            'profile.representatives.*.*' => ['array:name,position,authority,nrs_number'],
            'profile.representatives.*.*.*' => ['nullable', 'string', 'max:2000'],
            'profile.title_changes' => ['sometimes', 'array', 'max:500'],
            'profile.title_changes.*' => ['array:date,change,representative,authority'],
            'profile.title_changes.*.*' => ['nullable', 'string', 'max:4000'],
            'profile.sections' => ['sometimes', 'array:1,2,4,6'],
            'work_details' => ['present', 'array', 'max:5000'],
            'work_details.*' => ['array:entry_id,conditions,location,methods,materials,tests,representative_name,representative_position'],
            'work_details.*.entry_id' => ['required', 'integer', 'min:1', 'distinct'],
            'document_version_ids' => ['present', 'array', 'max:500'],
            'document_version_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
        foreach (['conditions', 'location', 'methods', 'materials', 'tests', 'representative_name', 'representative_position'] as $key) {
            $rules['work_details.*.'.$key] = ['nullable', 'string', 'max:10000'];
        }
        foreach ([1, 2, 4, 6] as $number) {
            $rules['profile.sections.'.$number] = ['sometimes', 'array', 'max:1000'];
            $rules['profile.sections.'.$number.'.*'] = ['array:'.implode(',', array_keys(GeneralJournalDocumentDefinition::sections()[$number]['columns']))];
            $rules['profile.sections.'.$number.'.*.*'] = ['nullable', 'string', 'max:10000'];
        }
        if (array_diff(array_keys($data), ['mode', 'expected_revision', 'operation_key', 'correction_reason', 'profile', 'work_details', 'document_version_ids']) !== []
            || strlen(CanonicalJson::encode($data)) > 2097152
            || Validator::make($data, $rules)->fails()) {
            throw new BusinessLogicException(trans_message('general_journal.invalid_data'), 422);
        }
        return $data;
    }
}
