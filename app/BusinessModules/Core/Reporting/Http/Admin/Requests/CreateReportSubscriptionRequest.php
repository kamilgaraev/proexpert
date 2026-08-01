<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

use App\BusinessModules\Core\Reporting\Domain\DTO\CreateReportSubscriptionData;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionFrequency;
use DateTimeZone;

final class CreateReportSubscriptionRequest extends ReportFormRequest
{
    public function rules(): array
    {
        return [
            'saved_view_id' => ['required', 'string', ...$this->canonicalUlidRules()],
            'frequency' => ['required', 'in:daily,weekly,monthly'],
            'weekday' => ['present', 'nullable', 'integer', 'between:1,7'],
            'day_of_month' => ['present', 'nullable', 'integer', 'between:1,31'],
            'local_time' => ['required', 'date_format:H:i'],
            'timezone' => ['required', 'timezone'],
            'period_policy' => ['required', 'array', 'min:1'],
            'format' => ['required', 'in:csv,xlsx,pdf'],
            ...$this->subscriptionForbiddenClientFieldsRules(),
        ];
    }

    protected function acceptedBodyFields(): array
    {
        return ['saved_view_id', 'frequency', 'weekday', 'day_of_month', 'local_time', 'timezone', 'period_policy', 'format'];
    }

    public function toData(): CreateReportSubscriptionData
    {
        return new CreateReportSubscriptionData(
            (string) $this->validated('saved_view_id'),
            ReportSubscriptionFrequency::from((string) $this->validated('frequency')),
            $this->validated('weekday') === null ? null : (int) $this->validated('weekday'),
            $this->validated('day_of_month') === null ? null : (int) $this->validated('day_of_month'),
            (string) $this->validated('local_time'),
            new DateTimeZone((string) $this->validated('timezone')),
            $this->validated('period_policy'),
            (string) $this->validated('format'),
        );
    }
}
