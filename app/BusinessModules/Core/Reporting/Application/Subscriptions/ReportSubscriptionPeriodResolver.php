<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Subscriptions;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportRunData;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionExecutionInput;
use DateTimeImmutable;

final class ReportSubscriptionPeriodResolver
{
    public const MODES = ['fixed_filters', 'previous_day', 'previous_week', 'previous_month'];

    private const DATE_FIELDS = [['period_from', 'period_to'], ['period_start', 'period_end'], ['day_from', 'day_to']];

    public function validate(ReportSubscriptionExecutionInput $input): void
    {
        $policy = $input->periodPolicy;
        if (array_keys($policy) !== ['mode'] || ! in_array($policy['mode'], self::MODES, true)) {
            $this->invalid();
        }
        if ($policy['mode'] !== 'fixed_filters') {
            $this->dateFields($input);
        }
    }

    public function runData(ReportSubscriptionExecutionInput $input, DateTimeImmutable $scheduledFor): CreateReportRunData
    {
        try {
            $this->validate($input);
        } catch (ReportContractException $exception) {
            if ($input->periodPolicyVersion !== null) {
                throw $exception;
            }

            return $input->runData($scheduledFor);
        }
        if ($input->periodPolicy['mode'] === 'fixed_filters') {
            return $input->runData($scheduledFor);
        }

        $local = $scheduledFor->setTimezone($input->timezone)->setTime(0, 0);
        [$from, $to] = match ($input->periodPolicy['mode']) {
            'previous_day' => [$local->modify('-1 day'), $local->modify('-1 day')],
            'previous_week' => [$local->modify('monday this week')->modify('-7 days'), $local->modify('monday this week')->modify('-1 day')],
            'previous_month' => [$local->modify('first day of this month')->modify('-1 month'), $local->modify('first day of this month')->modify('-1 day')],
        };
        [$fromField, $toField] = $this->dateFields($input);

        return new CreateReportRunData(
            $input->reportCode,
            new ReportFilterSet(array_replace($input->filters->values, [
                $fromField => $from->format('Y-m-d'),
                $toField => $to->format('Y-m-d'),
            ])),
            $input->comparison,
            $scheduledFor,
            $input->locale,
            $input->savedViewId,
            $input->savedViewReference,
        );
    }

    private function dateFields(ReportSubscriptionExecutionInput $input): array
    {
        $matches = array_values(array_filter(self::DATE_FIELDS, static fn (array $fields): bool =>
            array_key_exists($fields[0], $input->filters->values) && array_key_exists($fields[1], $input->filters->values)
        ));
        if (count($matches) !== 1) {
            $this->invalid();
        }

        return $matches[0];
    }

    private function invalid(): never
    {
        throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID, ['fields' => ['period_policy']]);
    }
}
