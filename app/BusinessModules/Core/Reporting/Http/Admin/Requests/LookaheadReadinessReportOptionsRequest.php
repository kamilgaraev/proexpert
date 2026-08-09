<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use DateTimeImmutable;

final class LookaheadReadinessReportOptionsRequest extends ReportFormRequest
{
    public function rules(): array
    {
        return [
            'as_of' => ['required', 'date_format:Y-m-d'],
            'horizon_days' => ['sometimes', 'integer', 'min:1', 'max:366'],
            ...$this->forbiddenClientFieldsRules(),
            'project_id' => ['prohibited'],
            'current_project_id' => ['prohibited'],
            'scope' => ['prohibited'],
            'actor_id' => ['prohibited'],
        ];
    }

    protected function acceptedQueryFields(): array
    {
        return ['as_of', 'horizon_days'];
    }

    public function asOf(): DateTimeImmutable
    {
        $asOf = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $this->validated('as_of'));

        return $asOf !== false
            ? $asOf
            : throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_REQUEST_INVALID,
                ['fields' => ['as_of']],
            );
    }

    public function horizonDays(): int
    {
        return (int) ($this->validated('horizon_days') ?? 30);
    }
}
