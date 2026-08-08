<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Http\Admin\Support\ReportAsOfParser;
use DateTimeImmutable;

final class AcceptedProductionReportOptionsRequest extends ReportFormRequest
{
    public function rules(): array
    {
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
            'period_from' => ['required', 'string', 'date_format:Y-m-d'],
            'period_to' => ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:period_from'],
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
            $this->invalid(['as_of']);
        }

        return $asOf;
    }

    public function periodFrom(): DateTimeImmutable
    {
        return $this->validatedDate('period_from');
    }

    public function periodTo(): DateTimeImmutable
    {
        return $this->validatedDate('period_to');
    }

    private function validatedDate(string $field): DateTimeImmutable
    {
        $value = $this->validated($field);
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (! $date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            $this->invalid([$field]);
        }

        return $date;
    }

    private function invalid(array $fields): never
    {
        throw ReportContractException::fromCode(
            ReportErrorCode::REPORT_REQUEST_INVALID,
            ['fields' => $fields],
        );
    }
}
