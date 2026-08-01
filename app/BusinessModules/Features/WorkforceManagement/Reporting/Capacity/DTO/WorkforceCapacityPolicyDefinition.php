<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO;

use DateTimeZone;
use InvalidArgumentException;

final readonly class WorkforceCapacityPolicyDefinition
{
    public function __construct(
        public string $version,
        public string $timezone,
        public array $calendarPrecedence,
        public array $assignmentStatuses,
        public array $unavailabilityStatuses,
        public array $gapCodes,
        public array $captureKinds,
        public array $redactedFields,
    ) {}

    public static function v1(string $timezone): self
    {
        if (trim($timezone) === '' || ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('workforce_capacity_timezone_invalid');
        }

        return new self(
            version: 'workforce-capacity-policy.v1',
            timezone: $timezone,
            calendarPrecedence: ['schedule_day', 'weekly_pattern', 'gap'],
            assignmentStatuses: ['active'],
            unavailabilityStatuses: ['approved'],
            gapCodes: [
                'ambiguous_attribution',
                'capture_gap',
                'cross_scope_unavailability',
                'inactive_schedule',
                'inactive_staff_unit',
                'invalid_schedule',
                'missing_schedule',
                'source_contract_missing',
            ],
            captureKinds: ['change_capture', 'scheduled_close', 'manual_recompute'],
            redactedFields: [
                'address',
                'actual_hours',
                'base_salary',
                'comment',
                'destination',
                'email',
                'first_name',
                'last_name',
                'middle_name',
                'overtime',
                'payroll_amount',
                'personnel_number',
                'phone',
                'purpose',
                'qr_payload',
                'salary',
                'salary_amount',
            ],
        );
    }

    public function canonical(): array
    {
        return [
            'version' => $this->version,
            'timezone' => $this->timezone,
            'effective_date_semantics' => 'inclusive',
            'staff_unit_rule' => 'active_effective_not_deleted',
            'assignment_statuses' => $this->assignmentStatuses,
            'unavailability_statuses' => $this->unavailabilityStatuses,
            'absence_type_rule' => 'affects_payroll_true_v1',
            'project_attribution_rule' => 'exact_or_null_bucket_no_derived_split',
            'calendar_precedence' => $this->calendarPrecedence,
            'weekly_pattern_keys' => ['1', '2', '3', '4', '5', '6', '7'],
            'missing_schedule_rule' => 'gap',
            'rounding' => ['fte_scale' => 4, 'hours_scale' => 2, 'mode' => 'half_up_at_render_boundary'],
            'formula_order' => [
                'authorized_fte',
                'assigned_fte',
                'approved_unavailability_fte',
                'available_fte',
                'open_fte',
                'overallocated_fte',
                'scheduled_hours',
                'capacity_status',
            ],
            'status_precedence' => ['gap', 'overallocated', 'unavailable', 'understaffed', 'balanced'],
            'source_item_order' => [
                'staff_unit',
                'assignment',
                'employee_lifecycle',
                'schedule',
                'schedule_day',
                'absence',
                'business_trip',
                'capacity_gap',
            ],
            'capture_kinds' => $this->captureKinds,
            'gap_codes' => $this->gapCodes,
            'redacted_fields' => $this->redactedFields,
        ];
    }

    public function hash(): string
    {
        return hash('sha256', json_encode($this->canonical(), JSON_THROW_ON_ERROR));
    }
}
