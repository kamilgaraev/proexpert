<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

final class SetReportWorkspaceFavouritesRequest extends ReportFormRequest
{
    public function rules(): array
    {
        return [
            'report_codes' => ['required', 'array'],
            'report_codes.*' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{2,63}$/'],
            'owner_id' => ['prohibited'],
            ...$this->forbiddenClientFieldsRules(),
        ];
    }

    protected function acceptedBodyFields(): array
    {
        return ['report_codes'];
    }

    public function reportCodes(): array
    {
        return (array) $this->validated('report_codes');
    }
}
