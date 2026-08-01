<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\DTO;

use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Enums\PayrollReadinessReason;

final readonly class PayrollReadinessPolicyDefinition
{
    public function __construct(
        public string $version,
        public string $timezone,
        public string $calendarMode,
        public array $checkOrder,
        public array $allowedReasons,
        public array $blockingSeverities,
        public array $redactedFields,
    ) {}

    public static function v1(): self
    {
        return new self(
            version: 'payroll-readiness-policy.v1',
            timezone: 'UTC',
            calendarMode: 'none',
            checkOrder: [
                'period_validated',
                'source_present',
                'source_actual',
                'validation_clear',
                'accounting_clear',
            ],
            allowedReasons: array_map(
                static fn (PayrollReadinessReason $reason): string => $reason->value,
                PayrollReadinessReason::cases(),
            ),
            blockingSeverities: ['blocking'],
            redactedFields: [
                'employee_id',
                'employee_name',
                'hours',
                'amount',
                'message',
                'personnel_number',
                'salary_amount',
            ],
        );
    }

    public function allows(PayrollReadinessReason $reason): bool
    {
        return $this->allowsCode($reason->value);
    }

    public function allowsCode(string $reason): bool
    {
        return in_array($reason, $this->allowedReasons, true);
    }

    public function hash(): string
    {
        return hash('sha256', json_encode($this->canonical(), JSON_THROW_ON_ERROR));
    }

    public function canonical(): array
    {
        return [
            'version' => $this->version,
            'timezone' => $this->timezone,
            'calendar_mode' => $this->calendarMode,
            'check_order' => $this->checkOrder,
            'allowed_reasons' => $this->allowedReasons,
            'blocking_severities' => $this->blockingSeverities,
            'redacted_fields' => $this->redactedFields,
        ];
    }
}
