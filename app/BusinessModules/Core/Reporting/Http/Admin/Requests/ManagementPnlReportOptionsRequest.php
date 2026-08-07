<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Http\Admin\Support\ReportAsOfParser;
use DateTimeImmutable;

final class ManagementPnlReportOptionsRequest extends ReportFormRequest
{
    public function rules(): array
    {
        return [
            'period_from' => ['required', 'date_format:Y-m-d'],
            'period_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_from'],
            'scenario' => ['required', 'string', 'in:actual,forecast,plan'],
            'currencies' => ['sometimes', 'array'],
            'currencies.*' => ['string', 'in:RUB,USD,EUR', 'distinct'],
            'as_of' => ['required', 'string', static function (string $attribute, mixed $value, callable $fail): void {
                if (ReportAsOfParser::parse($value) === null) {
                    $fail(trans_message('reports.errors.report_request_invalid'));
                }
            }],
            ...$this->forbiddenClientFieldsRules(),
            'organization_id' => ['prohibited'],
            'current_organization_id' => ['prohibited'],
            'holding_organization_ids' => ['prohibited'],
            'project_id' => ['prohibited'],
            'project_ids' => ['prohibited'],
            'scope' => ['prohibited'],
            'actor_id' => ['prohibited'],
        ];
    }

    protected function acceptedQueryFields(): array
    {
        return ['period_from', 'period_to', 'scenario', 'currencies', 'as_of'];
    }

    public function asOf(): DateTimeImmutable
    {
        return ReportAsOfParser::parse($this->validated('as_of'))
            ?? throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID, ['fields' => ['as_of']]);
    }

    public function reportFilters(): array
    {
        return [
            'period_from' => (string) $this->validated('period_from'),
            'period_to' => (string) $this->validated('period_to'),
            'scenarios' => [(string) $this->validated('scenario')],
            'currencies' => array_values((array) $this->validated('currencies', [])),
        ];
    }
}
