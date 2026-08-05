<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Http\Admin\Support\ReportAsOfParser;
use DateTimeImmutable;

final class HoldingPerformanceReportOptionsRequest extends ReportFormRequest
{
    public function rules(): array
    {
        $periodFrom = $this->query('period_from');

        return [
            'as_of' => [
                'required',
                'string',
                static function (string $attribute, mixed $value, callable $fail): void {
                    if (ReportAsOfParser::parse($value) === null) {
                        $fail(trans_message('reports.errors.report_request_invalid'));
                    }
                },
            ],
            'period_from' => ['nullable', 'string', 'date_format:Y-m-d'],
            'period_to' => [
                'nullable',
                'string',
                'date_format:Y-m-d',
                static function (string $attribute, mixed $value, callable $fail) use ($periodFrom): void {
                    if (is_string($periodFrom) && is_string($value) && $value < $periodFrom) {
                        $fail(trans_message('reports.errors.report_request_invalid'));
                    }
                },
            ],
            ...$this->forbiddenClientFieldsRules(),
            'current_organization_id' => ['prohibited'],
            'holding_organization_ids' => ['prohibited'],
            'organization_ids' => ['prohibited'],
            'project_id' => ['prohibited'],
            'current_project_id' => ['prohibited'],
            'project_ids' => ['prohibited'],
            'scope' => ['prohibited'],
            'actor_id' => ['prohibited'],
        ];
    }

    protected function acceptedQueryFields(): array
    {
        return ['as_of', 'period_from', 'period_to'];
    }

    public function asOf(): DateTimeImmutable
    {
        $asOf = ReportAsOfParser::parse($this->validated('as_of'));
        if ($asOf === null) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_REQUEST_INVALID,
                ['fields' => ['as_of']],
            );
        }

        return $asOf;
    }

    public function periodFrom(): ?string
    {
        $value = $this->validated('period_from');

        return is_string($value) ? $value : null;
    }

    public function periodTo(): ?string
    {
        $value = $this->validated('period_to');

        return is_string($value) ? $value : null;
    }
}
