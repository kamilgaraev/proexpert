<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

use App\BusinessModules\Core\Reporting\Application\Subscriptions\ReportSubscriptionPeriodResolver;
use App\BusinessModules\Core\Reporting\Domain\DTO\UpdateReportSubscriptionData;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionFrequency;
use DateTimeZone;
use Illuminate\Validation\Rule;

final class UpdateReportSubscriptionRequest extends ReportSubscriptionRouteRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'saved_view_id' => ['sometimes', 'string', ...$this->canonicalUlidRules()],
            'frequency' => ['sometimes', 'in:daily,weekly,monthly'],
            'weekday' => ['sometimes', 'nullable', 'integer', 'between:1,7'],
            'day_of_month' => ['sometimes', 'nullable', 'integer', 'between:1,31'],
            'local_time' => ['sometimes', 'date_format:H:i'],
            'timezone' => ['sometimes', 'timezone'],
            'period_policy' => ['sometimes', 'array:mode', 'min:1'],
            'period_policy.mode' => ['required_with:period_policy', Rule::in(ReportSubscriptionPeriodResolver::MODES)],
            'format' => ['sometimes', 'in:csv,xlsx,pdf'],
        ];
    }

    protected function acceptedBodyFields(): array
    {
        return ['saved_view_id', 'frequency', 'weekday', 'day_of_month', 'local_time', 'timezone', 'period_policy', 'format'];
    }

    public function toData(): UpdateReportSubscriptionData
    {
        $changes = $this->safe()->only($this->acceptedBodyFields());

        if (isset($changes['frequency'])) {
            $changes['frequency'] = ReportSubscriptionFrequency::from((string) $changes['frequency']);
        }

        if (isset($changes['timezone'])) {
            $changes['timezone'] = new DateTimeZone((string) $changes['timezone']);
        }

        return new UpdateReportSubscriptionData($changes);
    }
}
