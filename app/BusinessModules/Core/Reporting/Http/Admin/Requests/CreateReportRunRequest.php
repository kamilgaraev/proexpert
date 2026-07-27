<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

use App\BusinessModules\Core\Reporting\Application\Input\CreateReportRunData;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use DateTimeImmutable;
use DateTimeInterface;

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
                    if (!is_string($value)) {
                        $fail(trans_message('reports.errors.report_request_invalid'));

                        return;
                    }

                    $date = DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339, $value);
                    $errors = DateTimeImmutable::getLastErrors();

                    if ($date === false
                        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                        $fail(trans_message('reports.errors.report_request_invalid'));
                    }
                },
            ],
            'locale' => ['sometimes', 'string', 'regex:/^[a-z]{2}(?:-[A-Z]{2})?$/'],
            'saved_view_id' => ['sometimes', 'nullable', 'ulid'],
            ...$this->forbiddenClientFieldsRules(),
        ];
    }

    protected function acceptedBodyFields(): array
    {
        return ['filters', 'comparison', 'as_of', 'locale', 'saved_view_id'];
    }

    public function toData(string $reportCode): CreateReportRunData
    {
        return new CreateReportRunData(
            $reportCode,
            new ReportFilterSet((array) $this->validated('filters')),
            (array) $this->validated('comparison', []),
            new DateTimeImmutable((string) $this->validated('as_of')),
            (string) $this->validated('locale', 'ru-RU'),
            $this->validated('saved_view_id'),
        );
    }
}
