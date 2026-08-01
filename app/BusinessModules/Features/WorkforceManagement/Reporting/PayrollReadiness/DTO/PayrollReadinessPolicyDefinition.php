<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\DTO;

use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Enums\PayrollReadinessReason;
use InvalidArgumentException;

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
        public array $reasonEvidence,
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
            reasonEvidence: [
                PayrollReadinessReason::PERIOD_NOT_VALIDATED->value => [
                    'blocked_check' => 'period_validated',
                    'source_rows' => 'any',
                    'blocking_issues' => 'any',
                ],
                PayrollReadinessReason::SOURCE_EMPTY->value => [
                    'blocked_check' => 'source_present',
                    'source_rows' => 'none',
                    'blocking_issues' => 'any',
                ],
                PayrollReadinessReason::SOURCE_CHANGED->value => [
                    'blocked_check' => 'source_actual',
                    'source_rows' => 'required',
                    'blocking_issues' => 'any',
                ],
                PayrollReadinessReason::VALIDATION_BLOCKERS->value => [
                    'blocked_check' => 'validation_clear',
                    'source_rows' => 'required',
                    'blocking_issues' => 'required',
                ],
                PayrollReadinessReason::ACCOUNTING_BLOCKERS->value => [
                    'blocked_check' => 'accounting_clear',
                    'source_rows' => 'required',
                    'blocking_issues' => 'required',
                ],
                PayrollReadinessReason::LOCKED->value => [
                    'blocked_check' => null,
                    'source_rows' => 'required',
                    'blocking_issues' => 'none',
                ],
            ],
        );
    }

    public function allows(PayrollReadinessReason $reason): bool
    {
        return $this->allowsCode($reason->value);
    }

    public function allowsCode(string $reason): bool
    {
        return in_array($reason, $this->allowedReasons, true)
            && array_key_exists($reason, $this->reasonEvidence);
    }

    public function checkStates(PayrollReadinessReason $reason): array
    {
        $rule = $this->rule($reason);
        $blockedCheck = $rule['blocked_check'];
        $blocked = $blockedCheck !== null;
        $states = [];

        foreach ($this->checkOrder as $check) {
            $states[$check] = $blocked
                ? ($check === $blockedCheck ? 'blocked' : 'passed')
                : 'passed';

            if ($check === $blockedCheck) {
                $blocked = false;

                continue;
            }

            if ($blockedCheck !== null && in_array('blocked', $states, true)) {
                $states[$check] = 'not_evaluated';
            }
        }

        return $states;
    }

    public function assertEvidenceState(
        PayrollReadinessReason $reason,
        int $sourceRowCount,
        int $blockerCount,
        array $blockerCodes,
    ): void {
        $rule = $this->rule($reason);
        $invalidBlockerCodes = array_filter(
            $blockerCodes,
            static fn (mixed $code): bool => ! is_string($code)
                || preg_match('/^[a-z0-9_]{1,120}$/D', $code) !== 1,
        );
        $normalizedBlockerCodes = $invalidBlockerCodes === [] ? $blockerCodes : [];
        sort($normalizedBlockerCodes, SORT_STRING);
        $normalizedBlockerCodes = array_values(array_unique($normalizedBlockerCodes));
        $blockerCodesValid = $invalidBlockerCodes === []
            && $normalizedBlockerCodes === $blockerCodes
            && count($blockerCodes) <= $blockerCount
            && ($blockerCount === 0) === ($blockerCodes === []);
        $sourceRowsValid = match ($rule['source_rows']) {
            'none' => $sourceRowCount === 0,
            'required' => $sourceRowCount > 0,
            'any' => true,
            default => false,
        };
        $blockingIssuesValid = match ($rule['blocking_issues']) {
            'none' => $blockerCount === 0 && $blockerCodes === [],
            'required' => $blockerCount > 0 && $blockerCodes !== [],
            'any' => true,
            default => false,
        };

        if (! $blockerCodesValid || ! $sourceRowsValid || ! $blockingIssuesValid) {
            throw new InvalidArgumentException('payroll_readiness_reason_evidence_mismatch');
        }
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
            'reason_evidence' => $this->reasonEvidence,
        ];
    }

    private function rule(PayrollReadinessReason $reason): array
    {
        $rule = $this->reasonEvidence[$reason->value] ?? null;

        if (! is_array($rule)
            || ! array_key_exists('blocked_check', $rule)
            || ! isset($rule['source_rows'], $rule['blocking_issues'])) {
            throw new InvalidArgumentException('payroll_readiness_reason_policy_invalid');
        }

        return $rule;
    }
}
