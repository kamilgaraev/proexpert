<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

use App\BusinessModules\Core\Reporting\Application\Input\CreateReportRunData;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Http\Admin\Support\ReportAsOfParser;

final class CreateReportRunRequest extends ReportFormRequest
{
    public function validationData(): array
    {
        return [
            ...parent::validationData(),
            '_report_code' => $this->route('reportCode'),
        ];
    }

    public function rules(): array
    {
        return [
            '_report_code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{2,63}$/'],
            'filters' => ['present', 'array'],
            'comparison' => ['sometimes', 'array'],
            'as_of' => [
                'required',
                'string',
                static function (string $attribute, mixed $value, callable $fail): void {
                    if (ReportAsOfParser::parse($value) === null) {
                        $fail(trans_message('reports.errors.report_request_invalid'));
                    }
                },
            ],
            'locale' => ['sometimes', 'string', 'regex:/^[a-z]{2}(?:-[A-Z]{2})?$/'],
            'saved_view_id' => ['sometimes', 'nullable', ...$this->canonicalUlidRules()],
            ...$this->forbiddenClientFieldsRules(),
        ];
    }

    protected function acceptedBodyFields(): array
    {
        return ['filters', 'comparison', 'as_of', 'locale', 'saved_view_id'];
    }

    protected function safeValidationFieldKey(string $field): string
    {
        return $field === '_report_code' ? 'report_code' : parent::safeValidationFieldKey($field);
    }

    public function reportCode(): string
    {
        return (string) $this->validated('_report_code');
    }

    public function toData(): CreateReportRunData
    {
        $asOf = ReportAsOfParser::parse($this->validated('as_of'));
        if ($asOf === null) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_REQUEST_INVALID,
                ['fields' => ['as_of']],
            );
        }

        return new CreateReportRunData(
            $this->reportCode(),
            new ReportFilterSet((array) $this->validated('filters')),
            (array) $this->validated('comparison', []),
            $asOf,
            (string) $this->validated('locale', 'ru-RU'),
            $this->validated('saved_view_id'),
        );
    }
}
