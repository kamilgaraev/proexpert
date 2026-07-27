<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

use App\BusinessModules\Core\Reporting\Application\Input\CreateReportRunData;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use DateTimeImmutable;

final class CreateReportRunRequest extends ReportFormRequest
{
    public function rules(): array
    {
        return [
            'filters' => ['present', 'array'],
            'comparison' => ['sometimes', 'array'],
            'as_of' => [
                'required',
                'string',
                static function (string $attribute, mixed $value, callable $fail): void {
                    if (self::parseAsOf($value) === null) {
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

    public function toData(string $reportCode): CreateReportRunData
    {
        $asOf = self::parseAsOf($this->validated('as_of'));
        if ($asOf === null) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_REQUEST_INVALID,
                ['fields' => ['as_of']],
            );
        }

        return new CreateReportRunData(
            $reportCode,
            new ReportFilterSet((array) $this->validated('filters')),
            (array) $this->validated('comparison', []),
            $asOf,
            (string) $this->validated('locale', 'ru-RU'),
            $this->validated('saved_view_id'),
        );
    }

    private static function parseAsOf(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value)
            || preg_match(
                '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-](?:[01][0-9]|2[0-3]):[0-5][0-9])$/D',
                $value,
            ) !== 1) {
            return null;
        }

        $format = str_contains($value, '.') ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP';
        $date = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date;
    }
}
