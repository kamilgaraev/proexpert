<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

final class AnswerEstimatePrerequisiteRequest extends RefreshEstimatePrerequisitesRequest
{
    public function rules(): array
    {
        return [...parent::rules(),
            'finding_key' => ['required', 'string', 'max:191'],
            'backlog_version' => ['required', 'string', 'regex:/\Asha256:[a-f0-9]{64}\z/D'],
            'question_key' => ['required', 'string', 'regex:/\Aprerequisite:[a-f0-9]{64}\z/D'],
            'value' => ['required', 'string', 'max:18', 'regex:/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,4})?\z/D'],
            'unit' => ['required', 'in:m2,m3,item'],
            'basis' => ['required', 'in:input,measurement,assumption'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
