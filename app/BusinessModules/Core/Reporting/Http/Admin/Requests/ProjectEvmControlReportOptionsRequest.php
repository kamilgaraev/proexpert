<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Http\Admin\Support\ReportAsOfParser;
use DateTimeImmutable;

final class ProjectEvmControlReportOptionsRequest extends ReportFormRequest
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
            ...$this->forbiddenClientFieldsRules(),
            'project_id' => ['prohibited'],
            'current_project_id' => ['prohibited'],
            'scope' => ['prohibited'],
            'actor_id' => ['prohibited'],
        ];
    }

    protected function acceptedQueryFields(): array
    {
        return ['as_of'];
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
}
