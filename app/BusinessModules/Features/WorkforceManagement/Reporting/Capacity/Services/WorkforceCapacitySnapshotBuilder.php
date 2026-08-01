<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCohortKey;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityEvidenceItem;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityPolicyDefinition;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacitySnapshot;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class WorkforceCapacitySnapshotBuilder
{
    private const SCHEMA_VERSION = 'workforce-capacity-source.v1';

    private const FORMULA_VERSION = 'workforce-capacity-formula.v1';

    private const TYPE_ORDER = [
        'staff_unit' => 1,
        'assignment' => 2,
        'employee_lifecycle' => 3,
        'schedule' => 4,
        'schedule_day' => 5,
        'absence' => 6,
        'business_trip' => 7,
        'capacity_gap' => 8,
    ];

    public function __construct(private WorkforceCapacityPolicyDefinition $policy) {}

    public function build(
        WorkforceCapacityCohortKey $key,
        string $captureKind,
        DateTimeImmutable $capturedAt,
        ?int $actorUserId,
        ?string $serviceActor,
        array $source,
    ): WorkforceCapacitySnapshot {
        $this->assertNoRestrictedFields($source);
        $staffUnit = $this->staffUnit($source['staff_unit'] ?? null, $key);
        $assignments = $this->activeAssignments((array) ($source['assignments'] ?? []), $key);
        $gaps = $this->normalizedGaps((array) ($source['gaps'] ?? []));

        if ($staffUnit === null) {
            $gaps[] = 'source_contract_missing';
        } elseif (! $this->effective($staffUnit, $key->asOfDate) || ! (bool) $staffUnit['is_active']) {
            $gaps[] = 'inactive_staff_unit';
        }

        $authorized = $staffUnit === null ? null : WorkforceCapacityDecimal::parse($staffUnit['headcount'], 4);
        $assigned = 0;
        foreach ($assignments as $assignment) {
            $assigned += WorkforceCapacityDecimal::parse($assignment['rate'], 4);
        }

        [$unavailable, $unavailabilityGaps] = $this->unavailability(
            $assignments,
            (array) ($source['absences'] ?? []),
            (array) ($source['business_trips'] ?? []),
            $key,
        );
        $gaps = [...$gaps, ...$unavailabilityGaps];
        [$scheduledHours, $calendarGaps] = $this->scheduledHours(
            $assignments,
            (array) ($source['schedules'] ?? []),
            (array) ($source['schedule_days'] ?? []),
            $key,
        );
        $gaps = [...$gaps, ...$calendarGaps];
        $gaps = $this->normalizedGaps($gaps);

        $available = max($assigned - $unavailable, 0);
        $open = $authorized === null ? null : max($authorized - $assigned, 0);
        $overallocated = $authorized === null ? null : max($assigned - $authorized, 0);
        $status = $this->status($gaps, $assigned, $available, $open, $overallocated);
        $items = $this->evidenceItems($staffUnit, $assignments, $source, $gaps, $key);
        $itemsHashValue = array_map(
            static fn (WorkforceCapacityEvidenceItem $item, int $position): array => [
                'position' => $position + 1,
                'type' => $item->sourceType,
                'content_hash' => $item->contentHash,
            ],
            $items,
            array_keys($items),
        );
        $itemsCanonical = json_encode($this->canonicalValue($itemsHashValue), JSON_THROW_ON_ERROR);
        $itemsHash = hash('sha256', $itemsCanonical);
        $sourceCounts = array_fill_keys(array_keys(self::TYPE_ORDER), 0);
        foreach ($items as $item) {
            $sourceCounts[$item->sourceType]++;
        }

        $state = [
            ...$key->canonical(),
            'capture_kind' => $captureKind,
            'source_schema_version' => self::SCHEMA_VERSION,
            'formula_version' => self::FORMULA_VERSION,
            'policy_hash' => $this->policy->hash(),
            'authorized_fte' => $authorized === null ? null : WorkforceCapacityDecimal::format($authorized, 4),
            'assigned_fte' => WorkforceCapacityDecimal::format($assigned, 4),
            'available_fte' => WorkforceCapacityDecimal::format($available, 4),
            'approved_unavailability_fte' => WorkforceCapacityDecimal::format($unavailable, 4),
            'open_fte' => $open === null ? null : WorkforceCapacityDecimal::format($open, 4),
            'overallocated_fte' => $overallocated === null ? null : WorkforceCapacityDecimal::format($overallocated, 4),
            'scheduled_hours' => $scheduledHours === null ? null : WorkforceCapacityDecimal::format($scheduledHours, 2),
            'capacity_status' => $status,
            'gap_codes' => $gaps,
            'source_counts' => $sourceCounts,
            'item_count' => count($items),
        ];
        $stateCanonical = json_encode($this->canonicalValue($state), JSON_THROW_ON_ERROR);
        $stateHash = hash('sha256', $stateCanonical);
        $sourceValue = [
            'schema' => self::SCHEMA_VERSION,
            'formula' => self::FORMULA_VERSION,
            'policy_hash' => $this->policy->hash(),
            'state_hash' => $stateHash,
            'items_hash' => $itemsHash,
        ];
        $sourceCanonical = json_encode($this->canonicalValue($sourceValue), JSON_THROW_ON_ERROR);
        $sourceHash = hash('sha256', $sourceCanonical);

        return new WorkforceCapacitySnapshot(
            key: $key,
            captureKind: $captureKind,
            capturedAt: $capturedAt,
            actorUserId: $actorUserId,
            serviceActor: $serviceActor,
            schemaVersion: self::SCHEMA_VERSION,
            formulaVersion: self::FORMULA_VERSION,
            policy: $this->policy,
            authorizedFte: $state['authorized_fte'],
            assignedFte: $state['assigned_fte'],
            availableFte: $state['available_fte'],
            approvedUnavailabilityFte: $state['approved_unavailability_fte'],
            openFte: $state['open_fte'],
            overallocatedFte: $state['overallocated_fte'],
            scheduledHours: $state['scheduled_hours'],
            capacityStatus: $status,
            gapCodes: $gaps,
            sourceCounts: $sourceCounts,
            itemCount: count($items),
            itemsHash: $itemsHash,
            itemsCanonical: $itemsCanonical,
            stateHash: $stateHash,
            stateCanonical: $stateCanonical,
            sourceHash: $sourceHash,
            sourceCanonical: $sourceCanonical,
            items: $items,
        );
    }

    private function staffUnit(mixed $value, WorkforceCapacityCohortKey $key): ?array
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value)
            || (int) ($value['id'] ?? 0) !== $key->staffUnitId
            || (int) ($value['organization_id'] ?? 0) !== $key->organizationId) {
            throw new InvalidArgumentException('workforce_capacity_staff_unit_lineage_mismatch');
        }

        return $value;
    }

    private function activeAssignments(array $rows, WorkforceCapacityCohortKey $key): array
    {
        $active = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            if ((int) ($row['organization_id'] ?? 0) !== $key->organizationId
                || (int) ($row['staff_unit_id'] ?? 0) !== $key->staffUnitId) {
                throw new InvalidArgumentException('workforce_capacity_assignment_lineage_mismatch');
            }

            $projectId = $this->nullablePositiveInt($row['project_id'] ?? null);
            if ($projectId !== $key->projectId
                || ! in_array((string) ($row['status'] ?? ''), $this->policy->assignmentStatuses, true)
                || ($row['deleted_at'] ?? null) !== null
                || ! $this->effective($row, $key->asOfDate)) {
                continue;
            }

            $active[] = $row;
        }

        usort($active, static fn (array $left, array $right): int => (int) $left['id'] <=> (int) $right['id']);

        return $active;
    }

    private function unavailability(
        array $assignments,
        array $absences,
        array $trips,
        WorkforceCapacityCohortKey $key,
    ): array {
        $absenceByEmployee = [];
        foreach ($absences as $absence) {
            $absence = (array) $absence;
            if ((int) ($absence['organization_id'] ?? 0) !== $key->organizationId) {
                throw new InvalidArgumentException('workforce_capacity_absence_lineage_mismatch');
            }
            if (in_array((string) ($absence['status'] ?? ''), $this->policy->unavailabilityStatuses, true)
                && (bool) ($absence['affects_payroll'] ?? false)
                && $this->effectiveRange($absence, $key->asOfDate, 'start_date', 'end_date')) {
                $absenceByEmployee[(int) $absence['employee_id']] = true;
            }
        }

        $tripByEmployee = [];
        $crossScope = [];
        foreach ($trips as $trip) {
            $trip = (array) $trip;
            if ((int) ($trip['organization_id'] ?? 0) !== $key->organizationId) {
                throw new InvalidArgumentException('workforce_capacity_business_trip_lineage_mismatch');
            }
            if (! in_array((string) ($trip['status'] ?? ''), $this->policy->unavailabilityStatuses, true)
                || ! $this->effectiveRange($trip, $key->asOfDate, 'start_date', 'end_date')) {
                continue;
            }

            $employeeId = (int) ($trip['employee_id'] ?? 0);
            $tripProjectId = $this->nullablePositiveInt($trip['project_id'] ?? null);
            if ($tripProjectId !== null && $tripProjectId !== $key->projectId) {
                $crossScope[$employeeId] = true;
            } else {
                $tripByEmployee[$employeeId] = true;
            }
        }

        $unavailable = 0;
        $gaps = [];
        foreach ($assignments as $assignment) {
            $employeeId = (int) $assignment['employee_id'];
            if (isset($crossScope[$employeeId])) {
                $gaps[] = 'cross_scope_unavailability';

                continue;
            }
            if (isset($absenceByEmployee[$employeeId]) || isset($tripByEmployee[$employeeId])) {
                $unavailable += WorkforceCapacityDecimal::parse($assignment['rate'], 4);
            }
        }

        return [$unavailable, $gaps];
    }

    private function scheduledHours(
        array $assignments,
        array $schedules,
        array $scheduleDays,
        WorkforceCapacityCohortKey $key,
    ): array {
        $scheduleById = [];
        foreach ($schedules as $schedule) {
            $schedule = (array) $schedule;
            if ((int) ($schedule['organization_id'] ?? 0) !== $key->organizationId) {
                throw new InvalidArgumentException('workforce_capacity_schedule_lineage_mismatch');
            }
            $scheduleById[(int) $schedule['id']] = $schedule;
        }

        $overrideByScheduleAndDate = [];
        foreach ($scheduleDays as $day) {
            $day = (array) $day;
            if ((int) ($day['organization_id'] ?? 0) !== $key->organizationId) {
                throw new InvalidArgumentException('workforce_capacity_schedule_day_lineage_mismatch');
            }
            $overrideByScheduleAndDate[(int) $day['work_schedule_id']][(string) $day['work_date']] = $day;
        }

        $total = 0;
        $gaps = [];
        $monthStart = new DateTimeImmutable($key->monthStart);
        $monthEndExclusive = $monthStart->modify('first day of next month');

        foreach ($assignments as $assignment) {
            $scheduleId = $this->nullablePositiveInt($assignment['work_schedule_id'] ?? null);
            if ($scheduleId === null || ! isset($scheduleById[$scheduleId])) {
                $gaps[] = 'missing_schedule';

                continue;
            }

            $schedule = $scheduleById[$scheduleId];
            if (! (bool) ($schedule['is_active'] ?? false) || ($schedule['deleted_at'] ?? null) !== null) {
                $gaps[] = 'inactive_schedule';

                continue;
            }

            $pattern = $this->weekPattern($schedule['week_pattern'] ?? null);
            $assignmentHours = 0;
            foreach (new DatePeriod($monthStart, new DateInterval('P1D'), $monthEndExclusive) as $date) {
                $day = $date->format('Y-m-d');
                if (! $this->effective($assignment, $day)) {
                    continue;
                }

                $override = $overrideByScheduleAndDate[$scheduleId][$day] ?? null;
                if (is_array($override)) {
                    if (($override['day_type'] ?? null) === 'non_work') {
                        $hours = 0;
                    } elseif (($override['day_type'] ?? null) === 'work') {
                        $hours = WorkforceCapacityDecimal::parse($override['planned_hours'] ?? null, 2);
                    } else {
                        $gaps[] = 'invalid_schedule';

                        continue 2;
                    }
                } else {
                    $weekday = $date->format('N');
                    if (! array_key_exists($weekday, $pattern)) {
                        $gaps[] = 'invalid_schedule';

                        continue 2;
                    }
                    $hours = WorkforceCapacityDecimal::parse($pattern[$weekday], 2);
                }
                $assignmentHours += $hours;
            }

            $rate = WorkforceCapacityDecimal::parse($assignment['rate'], 4);
            $total += WorkforceCapacityDecimal::multiply($assignmentHours, 2, $rate, 4, 2);
        }

        return [$gaps === [] ? $total : null, $gaps];
    }

    private function evidenceItems(
        ?array $staffUnit,
        array $assignments,
        array $source,
        array $gaps,
        WorkforceCapacityCohortKey $key,
    ): array {
        $rows = $staffUnit === null ? [] : [['type' => 'staff_unit', 'row' => $staffUnit]];
        foreach ($assignments as $row) {
            $rows[] = ['type' => 'assignment', 'row' => $row];
        }
        foreach (['employee_lifecycle', 'schedules', 'schedule_days', 'absences', 'business_trips'] as $sourceKey) {
            $type = match ($sourceKey) {
                'employee_lifecycle' => 'employee_lifecycle',
                'schedules' => 'schedule',
                'schedule_days' => 'schedule_day',
                'absences' => 'absence',
                'business_trips' => 'business_trip',
            };
            foreach ((array) ($source[$sourceKey] ?? []) as $row) {
                $rows[] = ['type' => $type, 'row' => (array) $row];
            }
        }
        foreach ($gaps as $gap) {
            $rows[] = ['type' => 'capacity_gap', 'row' => ['gap_code' => $gap]];
        }

        usort($rows, static function (array $left, array $right): int {
            $type = self::TYPE_ORDER[$left['type']] <=> self::TYPE_ORDER[$right['type']];

            return $type !== 0 ? $type : ((int) ($left['row']['id'] ?? 0) <=> (int) ($right['row']['id'] ?? 0));
        });

        $items = [];
        foreach ($rows as $entry) {
            $type = $entry['type'];
            $row = $this->canonicalValue($entry['row']);
            $sourceId = isset($row['id']) ? (int) $row['id'] : null;
            $employeeId = isset($row['employee_id']) ? (int) $row['employee_id'] : null;
            $evidence = $row;
            unset($evidence['employee_id']);
            $publicLineage = array_filter([
                'organization_id' => $key->organizationId,
                'staff_unit_id' => $key->staffUnitId,
                'project_id' => $key->projectId,
                'month_start' => $key->monthStart,
                'effective_from' => $row['valid_from'] ?? $row['start_date'] ?? null,
                'effective_to' => $row['valid_to'] ?? $row['end_date'] ?? null,
                'status' => $row['status'] ?? null,
                'code' => $row['gap_code'] ?? $row['day_type'] ?? null,
            ], static fn (mixed $value): bool => $value !== null);
            $sourceCanonical = json_encode($this->canonicalValue(['type' => $type, 'source' => $row]), JSON_THROW_ON_ERROR);
            $revision = hash('sha256', $sourceCanonical);
            $contentValue = [
                'type' => $type,
                'source_id' => $sourceId,
                'revision' => $revision,
                'lineage' => $publicLineage,
                'evidence' => $evidence,
                'sealed_employee_id' => $employeeId > 0 ? $employeeId : null,
            ];
            $contentCanonical = json_encode($this->canonicalValue($contentValue), JSON_THROW_ON_ERROR);
            $content = hash('sha256', $contentCanonical);
            $items[] = new WorkforceCapacityEvidenceItem(
                sourceType: $type,
                sourceId: $sourceId,
                sourceRevisionHash: $revision,
                sourceCanonical: $sourceCanonical,
                contentHash: $content,
                lineage: $publicLineage,
                evidence: $evidence,
                contentCanonical: $contentCanonical,
                sealedEmployeeId: $employeeId > 0 ? $employeeId : null,
            );
        }

        return $items;
    }

    private function weekPattern(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }

    private function effective(array $row, string $date): bool
    {
        return $this->effectiveRange($row, $date, 'valid_from', 'valid_to');
    }

    private function effectiveRange(array $row, string $date, string $fromKey, string $toKey): bool
    {
        $from = (string) ($row[$fromKey] ?? '');
        $to = $row[$toKey] ?? null;

        return $from !== '' && $from <= $date && ($to === null || (string) $to >= $date);
    }

    private function status(array $gaps, int $assigned, int $available, ?int $open, ?int $overallocated): string
    {
        if ($gaps !== []) {
            return 'gap';
        }
        if ($overallocated !== null && $overallocated > 0) {
            return 'overallocated';
        }
        if ($assigned > 0 && $available === 0) {
            return 'unavailable';
        }
        if ($open !== null && $open > 0) {
            return 'understaffed';
        }

        return 'balanced';
    }

    private function normalizedGaps(array $gaps): array
    {
        foreach ($gaps as $gap) {
            if (! is_string($gap) || ! in_array($gap, $this->policy->gapCodes, true)) {
                throw new InvalidArgumentException('workforce_capacity_gap_code_invalid');
            }
        }
        sort($gaps, SORT_STRING);

        return array_values(array_unique($gaps));
    }

    private function assertNoRestrictedFields(array $source): void
    {
        $restricted = array_fill_keys($this->policy->redactedFields, true);
        $walk = function (mixed $value) use (&$walk, $restricted): void {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $key => $nested) {
                if (is_string($key) && isset($restricted[strtolower($key)])) {
                    throw new InvalidArgumentException('workforce_capacity_restricted_source_field');
                }
                $walk($nested);
            }
        };
        $walk($source);
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $integer = (int) $value;
        if ($integer < 1) {
            throw new InvalidArgumentException('workforce_capacity_identity_invalid');
        }

        return $integer;
    }

    private function canonicalValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $nested) {
            $value[$key] = $this->canonicalValue($nested);
        }

        return $value;
    }

    private function hash(array $value): string
    {
        return hash('sha256', json_encode($this->canonicalValue($value), JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }
}
