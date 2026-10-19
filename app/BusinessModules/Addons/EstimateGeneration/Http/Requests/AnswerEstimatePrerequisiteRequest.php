<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

use Closure;

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
            'reason' => ['required', 'string', 'max:1000', static function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && strlen($value) > 1000) {
                    $fail(trans_message('estimate_generation.prerequisites.reason_too_long'));
                }
            }],
        ];
    }
}
