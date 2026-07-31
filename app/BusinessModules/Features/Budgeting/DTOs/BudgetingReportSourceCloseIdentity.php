<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class BudgetingReportSourceCloseIdentity
{
    public function __construct(
        public int $organizationId,
        public string $periodStart,
        public string $periodEnd,
        public string $scenarioIdentity,
        public string $planIdentity,
    ) {
        if ($this->organizationId <= 0) {
            throw new InvalidArgumentException('budgeting_report_source_close_organization_invalid');
        }

        if (!self::isDate($this->periodStart) || !self::isDate($this->periodEnd) || $this->periodStart > $this->periodEnd) {
            throw new InvalidArgumentException('budgeting_report_source_close_period_invalid');
        }

        if (trim($this->scenarioIdentity) === '' || trim($this->planIdentity) === '') {
            throw new InvalidArgumentException('budgeting_report_source_close_identity_invalid');
        }
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'scenario_identity' => $this->scenarioIdentity,
            'plan_identity' => $this->planIdentity,
        ];
    }

    private static function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        return $date instanceof DateTimeImmutable
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d') === $value;
    }
}
