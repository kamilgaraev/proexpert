<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

final class RecordRecentReportRequest extends ReportFormRequest
{
    public function validationData(): array
    {
        return [...parent::validationData(), '_report_code' => $this->route('reportCode')];
    }

    public function rules(): array
    {
        return [
            '_report_code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{2,63}$/'],
            'owner_id' => ['prohibited'],
            ...$this->forbiddenClientFieldsRules(),
        ];
    }

    protected function safeValidationFieldKey(string $field): string
    {
        return $field === '_report_code' ? 'report_code' : parent::safeValidationFieldKey($field);
    }

    public function reportCode(): string
    {
        return (string) $this->validated('_report_code');
    }
}
