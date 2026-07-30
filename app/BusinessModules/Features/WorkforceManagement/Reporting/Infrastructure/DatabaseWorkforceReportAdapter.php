<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Infrastructure;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCoverage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursorKeyset;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResultMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWarning;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportWarningSeverity;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Contracts\WorkforceReportDatabasePort;
use App\BusinessModules\Features\WorkforceManagement\Reporting\DTO\EffectiveAssignmentFact;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas\AttendanceExecutionFormula;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas\WorkforceCapacityFormula;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class DatabaseWorkforceReportAdapter implements WorkforceReportDatabasePort
{
    private const CAPACITY_FORMULA = 'workforce-capacity.v1';

    private const ATTENDANCE_FORMULA = 'attendance.v1';

    private const SCHEMA_VERSION = 'workforce-report-source.v1';

    private const SORTS = [
        'workforce_capacity' => [
            'month', 'department_name', 'position_name', 'project_name', 'planned_fte',
            'assigned_fte', 'vacancy_fte', 'capacity_hours', 'rate',
        ],
        'attendance_execution' => [
            'work_date', 'employee_name', 'project_name', 'site_name', 'shift',
            'eligible_hours', 'present_hours', 'overtime_hours', 'absence_hours',
            'execution_percent',
        ],
    ];

    public function __construct(
        private ConnectionInterface $connection,
        private WorkforceCapacityFormula $capacityFormula,
        private AttendanceExecutionFormula $attendanceFormula,
    ) {}

    public function forEmployee(int $organizationId, int $employeeId): array
    {
        return $this->connection->table('workforce_employee_assignments')
            ->where('organization_id', $organizationId)
            ->where('employee_id', $employeeId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->orderBy('valid_from')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): EffectiveAssignmentFact => new EffectiveAssignmentFact(
                assignmentId: (int) $row->id,
                organizationId: (int) $row->organization_id,
                employeeId: (int) $row->employee_id,
                staffUnitId: (int) $row->staff_unit_id,
                departmentId: (int) $row->department_id,
                positionId: (int) $row->position_id,
                projectId: $row->project_id === null ? null : (int) $row->project_id,
                workScheduleId: $row->work_schedule_id === null ? null : (int) $row->work_schedule_id,
                validFrom: new DateTimeImmutable((string) $row->valid_from),
                validToExclusive: $row->valid_to === null
                    ? null
                    : (new DateTimeImmutable((string) $row->valid_to))->modify('+1 day'),
                fte: (string) $row->rate,
                sourceVersion: (int) $row->id,
            ))
            ->all();
    }

    public function materializeCapacity(ReportScope $scope, ReportQuery $query): ReportSnapshotRef
    {
        $this->assertScope($scope, $query);
        $this->assertNoPostAsOfMutations($scope, $query, [
            'workforce_staff_units',
            'workforce_departments',
            'workforce_positions',
            'workforce_employees',
            'workforce_employee_assignments',
            'workforce_work_schedules',
            'workforce_work_schedule_days',
            'time_tracking_labor_rate_versions',
            'projects',
        ]);
        [$monthFrom, $monthTo] = $this->monthRange($query);
        $departmentIds = $this->authorizedIds($scope, 'department', $this->ids($query, 'department_ids'));
        $positionIds = $this->authorizedIds($scope, 'position', $this->ids($query, 'position_ids'));
        $requestedProjectIds = $this->ids($query, 'project_ids');
        $projectIds = $this->projectIds($scope, $query, false);
        $employmentTypes = $this->strings($query, 'employment_types');
        $rateTypes = $this->strings($query, 'rate_types');
        $currencies = $this->strings($query, 'currencies');
        $this->assertOrganizationIds('workforce_departments', $scope, $departmentIds);
        $this->assertOrganizationIds('workforce_positions', $scope, $positionIds);
        $staffUnits = $this->connection->table('workforce_staff_units as unit')
            ->join('workforce_departments as department', function ($join): void {
                $join->on('department.id', '=', 'unit.department_id')
                    ->on('department.organization_id', '=', 'unit.organization_id');
            })
            ->join('workforce_positions as position', function ($join): void {
                $join->on('position.id', '=', 'unit.position_id')
                    ->on('position.organization_id', '=', 'unit.organization_id');
            })
            ->where('unit.organization_id', $scope->organizationId)
            ->where('unit.created_at', '<=', $query->asOf->format('Y-m-d H:i:sP'))
            ->where('unit.is_active', true)
            ->whereNull('unit.deleted_at')
            ->when(
                $departmentIds !== [],
                static fn (Builder $builder): Builder => $builder->whereIn('unit.department_id', $departmentIds),
            )
            ->when(
                $positionIds !== [],
                static fn (Builder $builder): Builder => $builder->whereIn('unit.position_id', $positionIds),
            )
            ->whereDate('unit.valid_from', '<=', $monthTo->modify('last day of this month')->format('Y-m-d'))
            ->where(static function (Builder $builder) use ($monthFrom): void {
                $builder->whereNull('unit.valid_to')
                    ->orWhereDate('unit.valid_to', '>=', $monthFrom->format('Y-m-d'));
            })
            ->select([
                'unit.id',
                'unit.department_id',
                'department.name as department_name',
                'unit.position_id',
                'position.name as position_name',
                'unit.headcount',
                'unit.rate as unit_rate',
                'unit.valid_from',
                'unit.valid_to',
            ])
            ->orderBy('unit.id')
            ->get();
        $unitIds = $staffUnits->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $assignments = $unitIds === []
            ? collect()
            : $this->connection->table('workforce_employee_assignments as assignment')
                ->join('workforce_employees as employee', function ($join): void {
                    $join->on('employee.id', '=', 'assignment.employee_id')
                        ->on('employee.organization_id', '=', 'assignment.organization_id');
                })
                ->leftJoin('projects as project', function ($join): void {
                    $join->on('project.id', '=', 'assignment.project_id')
                        ->on('project.organization_id', '=', 'assignment.organization_id');
                })
                ->where('assignment.organization_id', $scope->organizationId)
                ->where('assignment.created_at', '<=', $query->asOf->format('Y-m-d H:i:sP'))
                ->whereIn('assignment.staff_unit_id', $unitIds)
                ->where('assignment.status', 'active')
                ->whereNull('assignment.deleted_at')
                ->whereNull('employee.deleted_at')
                ->when(
                    $projectIds !== [],
                    static fn (Builder $builder): Builder => $builder->whereIn('assignment.project_id', $projectIds),
                )
                ->whereDate('assignment.valid_from', '<=', $monthTo->modify('last day of this month')->format('Y-m-d'))
                ->where(static function (Builder $builder) use ($monthFrom): void {
                    $builder->whereNull('assignment.valid_to')
                        ->orWhereDate('assignment.valid_to', '>=', $monthFrom->format('Y-m-d'));
                })
                ->select([
                    'assignment.*',
                    'project.name as project_name',
                    'employee.employment_status',
                ])
                ->orderBy('assignment.id')
                ->get();
        $employeeIds = $assignments->pluck('employee_id')->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $ratesByEmployee = $employeeIds === []
            ? collect()
            : $this->connection->table('time_tracking_labor_rate_versions')
                ->where('organization_id', $scope->organizationId)
                ->whereIn('employee_id', $employeeIds)
                ->where('status', 'approved')
                ->where('approved_at', '<=', $query->asOf->format('Y-m-d H:i:sP'))
                ->whereDate('valid_from', '<=', $query->asOf->format('Y-m-d'))
                ->orderBy('employee_id')
                ->orderBy('valid_from')
                ->orderBy('id')
                ->get()
                ->groupBy('employee_id');
        $scheduleIds = $assignments->pluck('work_schedule_id')->filter()->unique()->values()->all();
        $scheduleDays = $scheduleIds === []
            ? collect()
            : $this->connection->table('workforce_work_schedule_days')
                ->where('organization_id', $scope->organizationId)
                ->whereIn('work_schedule_id', $scheduleIds)
                ->where('created_at', '<=', $query->asOf->format('Y-m-d H:i:sP'))
                ->whereBetween('work_date', [
                    $monthFrom->format('Y-m-d'),
                    $monthTo->modify('last day of this month')->format('Y-m-d'),
                ])
                ->orderBy('work_date')
                ->orderBy('id')
                ->get();
        $rows = [];
        $warnings = [];

        foreach ($this->months($monthFrom, $monthTo) as $month) {
            $asOf = $month->modify('last day of this month');
            if ($asOf > $query->asOf) {
                $asOf = $query->asOf;
            }
            foreach ($staffUnits as $unit) {
                $active = $assignments->filter(
                    static fn (object $assignment): bool => (int) $assignment->staff_unit_id === (int) $unit->id
                        && (string) $assignment->valid_from <= $asOf->format('Y-m-d')
                        && ($assignment->valid_to === null || $asOf->format('Y-m-d') <= (string) $assignment->valid_to),
                );
                $byEmployee = $active->groupBy('employee_id');
                foreach ($byEmployee as $employeeAssignments) {
                    $identities = $employeeAssignments->map(
                        static fn (object $assignment): string => implode(':', [
                            $assignment->staff_unit_id,
                            $assignment->project_id ?? 'none',
                            $assignment->rate,
                            $assignment->valid_from,
                            $assignment->valid_to ?? 'open',
                        ]),
                    )->unique();
                    if ($identities->count() > 1) {
                        throw new DomainException('WORKFORCE_ASSIGNMENT_OVERLAP');
                    }
                }
                $active = $active->unique(
                    static fn (object $assignment): string => implode(':', [
                        $assignment->employee_id,
                        $assignment->staff_unit_id,
                        $assignment->project_id ?? 'none',
                        $assignment->rate,
                        $assignment->valid_from,
                        $assignment->valid_to ?? 'open',
                    ]),
                );
                $groups = $active->groupBy(
                    static fn (object $assignment): string => $assignment->project_id === null
                        ? 'none'
                        : 'project:'.$assignment->project_id,
                );
                if ($groups->isEmpty()) {
                    if ($projectIds !== []) {
                        continue;
                    }
                    $groups = collect(['none' => collect()]);
                }
                $assignedFteByProject = [];
                foreach ($groups as $projectKey => $projectAssignments) {
                    $assignedFte = BigDecimal::zero();
                    foreach ($projectAssignments as $assignment) {
                        $assignedFte = $assignedFte->plus((string) $assignment->rate);
                    }
                    $assignedFteByProject[(string) $projectKey] = (string) $assignedFte;
                }
                $plannedFte = BigDecimal::of((string) $unit->headcount)
                    ->multipliedBy((string) $unit->unit_rate);
                $plannedFteByProject = $this->capacityFormula->allocatePlannedFte(
                    (string) $plannedFte,
                    $assignedFteByProject,
                );
                if (array_key_exists('none', $plannedFteByProject) && ! $groups->has('none')) {
                    $groups->put('none', collect());
                }
                $groups = $groups->sortKeys();

                foreach ($groups as $projectKey => $projectAssignments) {
                    $projectId = $projectKey === 'none'
                        ? null
                        : (int) substr((string) $projectKey, strlen('project:'));
                    if ($projectIds !== []
                        && ($projectId === null || ! in_array($projectId, $projectIds, true))) {
                        continue;
                    }
                    $projectPlannedFte = BigDecimal::of(
                        $plannedFteByProject[(string) $projectKey] ?? '0.00',
                    );
                    $ratedAssignments = $projectAssignments->map(
                        fn (object $assignment): array => [
                            'assignment' => $assignment,
                            'rate' => $this->effectiveRate(
                                $ratesByEmployee,
                                (int) $assignment->employee_id,
                                $asOf,
                            ),
                        ],
                    );
                    $rateGroups = $ratedAssignments->groupBy(
                        static fn (array $rated): string => implode(':', [
                            (string) $rated['assignment']->employment_status,
                            $rated['rate'] === null ? 'unrated' : implode(':', [
                                $rated['rate']->rate_type,
                                $rated['rate']->currency ?? 'none',
                                $rated['rate']->amount,
                            ]),
                        ]),
                    );
                    if ($rateGroups->isEmpty()) {
                        $rateGroups = collect(['none' => collect()]);
                    }
                    $assignedFteByRate = [];
                    foreach ($rateGroups as $rateKey => $ratedGroup) {
                        $assignedFteByRate[(string) $rateKey] = (string) $ratedGroup->reduce(
                            static fn (BigDecimal $sum, array $rated): BigDecimal => $sum->plus(
                                (string) $rated['assignment']->rate,
                            ),
                            BigDecimal::zero(),
                        );
                    }
                    $plannedFteByRate = $this->capacityFormula->allocatePlannedFte(
                        (string) $projectPlannedFte,
                        $assignedFteByRate,
                    );
                    if (array_key_exists('none', $plannedFteByRate) && ! $rateGroups->has('none')) {
                        $rateGroups->put('none', collect());
                    }

                    foreach ($rateGroups->sortKeys() as $rateKey => $ratedGroup) {
                        $rate = $ratedGroup->first()['rate'] ?? null;
                        $rateType = $rate === null ? 'unrated' : (string) $rate->rate_type;
                        $currency = $rate?->currency;
                        if (($currencies !== [] && ($currency === null || ! in_array($currency, $currencies, true)))
                            || ($rateTypes !== [] && ! in_array($rateType, $rateTypes, true))) {
                            continue;
                        }
                        $groupAssignments = $ratedGroup->map(
                            static fn (array $rated): object => $rated['assignment'],
                        );
                        $facts = $groupAssignments->map(
                            static fn (object $assignment): EffectiveAssignmentFact => new EffectiveAssignmentFact(
                                assignmentId: (int) $assignment->id,
                                organizationId: (int) $assignment->organization_id,
                                employeeId: (int) $assignment->employee_id,
                                staffUnitId: (int) $assignment->staff_unit_id,
                                departmentId: (int) $assignment->department_id,
                                positionId: (int) $assignment->position_id,
                                projectId: $assignment->project_id === null ? null : (int) $assignment->project_id,
                                workScheduleId: $assignment->work_schedule_id === null ? null : (int) $assignment->work_schedule_id,
                                validFrom: new DateTimeImmutable((string) $assignment->valid_from),
                                validToExclusive: $assignment->valid_to === null
                                    ? null
                                    : (new DateTimeImmutable((string) $assignment->valid_to))->modify('+1 day'),
                                fte: (string) $assignment->rate,
                                sourceVersion: (int) $assignment->id,
                            ),
                        )->all();
                        $allocatedPlannedFte = BigDecimal::of(
                            $plannedFteByRate[(string) $rateKey] ?? $plannedFteByRate['none'] ?? '0.00',
                        );
                        [$plannedCapacityHours, $capacityWarnings, $capacityScheduleRefs] =
                            $this->capacitySchedule(
                                $groupAssignments,
                                $scheduleDays,
                                $month,
                                $allocatedPlannedFte,
                            );
                        $metrics = $this->capacityFormula->calculate(
                            approvedFte: (string) $allocatedPlannedFte,
                            assignments: $facts,
                            plannedCapacityHours: $plannedCapacityHours,
                            rateType: $rateType,
                            rate: $rate === null ? null : (string) $rate->amount,
                            currency: $currency,
                        );
                        $rowWarnings = array_values(array_unique([
                            ...$metrics->qualityWarnings,
                            ...$capacityWarnings,
                        ]));
                        $warnings = array_merge($warnings, $rowWarnings);
                        $project = $groupAssignments->first() ?? $projectAssignments->first();
                        $rateRefs = $ratedGroup->filter(
                            static fn (array $rated): bool => $rated['rate'] !== null,
                        )->map(
                            static fn (array $rated): array => [
                                'type' => 'labor_rate_version',
                                'id' => (int) $rated['rate']->id,
                            ],
                        )->unique('id')->values()->all();
                        $row = [
                            'row_key' => hash('sha256', implode('|', [
                                $month->format('Y-m-01'),
                                $unit->id,
                                $projectKey,
                                $rateKey,
                            ])),
                            'month' => $month->format('Y-m-01'),
                            'staff_unit_id' => (int) $unit->id,
                            'department_id' => (int) $unit->department_id,
                            'department_name' => (string) $unit->department_name,
                            'position_id' => (int) $unit->position_id,
                            'position_name' => (string) $unit->position_name,
                            'project_id' => $projectId,
                            'project_name' => $project?->project_name,
                            'employment_type' => $project?->employment_status,
                            'rate_as_of' => $asOf->format('Y-m-d'),
                            'planned_fte' => $metrics->approvedFte,
                            'assigned_fte' => $metrics->assignedFte,
                            'vacancy_fte' => $metrics->vacancyFte,
                            'overstaffing_fte' => $metrics->overstaffingFte,
                            'vacancy_percent' => $metrics->vacancyPercent,
                            'planned_capacity_hours' => $metrics->plannedCapacityHours,
                            'capacity_hours' => $metrics->assignedCapacityHours,
                            'rate_type' => $metrics->rateType,
                            'rate' => $metrics->rate,
                            'currency' => $metrics->currency,
                            'period_cost_run_rate' => $metrics->periodCostRunRate,
                            'quality_warnings' => $rowWarnings,
                            'source_refs' => [
                                ['type' => 'staff_unit', 'id' => (int) $unit->id],
                                ...$groupAssignments->map(
                                    static fn (object $assignment): array => [
                                        'type' => 'assignment',
                                        'id' => (int) $assignment->id,
                                    ],
                                )->all(),
                                ...$rateRefs,
                                ...$capacityScheduleRefs,
                            ],
                        ];
                        if (($requestedProjectIds !== []
                                && ($projectId === null || ! in_array($projectId, $requestedProjectIds, true)))
                            || ($employmentTypes !== []
                                && ! in_array((string) $row['employment_type'], $employmentTypes, true))) {
                            continue;
                        }
                        $this->assertMaterializedRowScope($scope, $row);
                        $rows[] = $row;
                    }
                }
            }
        }

        return $this->persist(
            scope: $scope,
            query: $query,
            code: 'workforce_capacity',
            formulaVersion: self::CAPACITY_FORMULA,
            rowTable: 'workforce_capacity_snapshot_rows',
            rows: $rows,
            totals: $this->capacityTotals($rows),
            rowSchema: $this->capacitySchema(),
            warnings: array_values(array_unique($warnings)),
        );
    }

    public function materializeAttendance(ReportScope $scope, ReportQuery $query): ReportSnapshotRef
    {
        $this->assertScope($scope, $query);
        $this->assertNoPostAsOfMutations($scope, $query, [
            'workforce_employee_assignments',
            'workforce_employees',
            'workforce_work_schedules',
            'workforce_work_schedule_days',
            'workforce_absences',
            'workforce_absence_types',
            'workforce_attendance_corrections',
            'workforce_attendance_scan_events',
            'projects',
        ]);
        [$dayFrom, $dayTo] = $this->dayRange($query);
        $employeeFilterIds = $this->authorizedIds($scope, 'employee', $this->ids($query, 'employee_ids'));
        $projectFilterIds = $this->projectIds($scope, $query);
        $absenceTypeIds = $this->authorizedIds(
            $scope,
            'absence_type',
            $this->ids($query, 'absence_type_ids'),
        );
        $siteIds = $this->authorizedIds($scope, 'site', $this->ids($query, 'site_ids'));
        $shiftIds = $this->authorizedIds($scope, 'shift', $this->ids($query, 'shift_ids'));
        $statuses = $this->strings($query, 'statuses');
        $this->assertOrganizationIds('workforce_employees', $scope, $employeeFilterIds);
        $this->assertOrganizationIds('workforce_absence_types', $scope, $absenceTypeIds);
        $this->assertOrganizationIds('projects', $scope, $siteIds);
        $this->assertOrganizationIds('workforce_work_schedules', $scope, $shiftIds);
        $assignments = $this->connection->table('workforce_employee_assignments as assignment')
            ->join('workforce_employees as employee', function ($join): void {
                $join->on('employee.id', '=', 'assignment.employee_id')
                    ->on('employee.organization_id', '=', 'assignment.organization_id');
            })
            ->leftJoin('projects as project', function ($join): void {
                $join->on('project.id', '=', 'assignment.project_id')
                    ->on('project.organization_id', '=', 'assignment.organization_id');
            })
            ->leftJoin('workforce_work_schedules as schedule', function ($join): void {
                $join->on('schedule.id', '=', 'assignment.work_schedule_id')
                    ->on('schedule.organization_id', '=', 'assignment.organization_id');
            })
            ->where('assignment.organization_id', $scope->organizationId)
            ->where('assignment.created_at', '<=', $query->asOf->format('Y-m-d H:i:sP'))
            ->where('assignment.status', 'active')
            ->whereNull('assignment.deleted_at')
            ->when(
                $employeeFilterIds !== [],
                static fn (Builder $builder): Builder => $builder->whereIn(
                    'assignment.employee_id',
                    $employeeFilterIds,
                ),
            )
            ->when(
                $projectFilterIds !== [],
                static fn (Builder $builder): Builder => $builder->whereIn(
                    'assignment.project_id',
                    $projectFilterIds,
                ),
            )
            ->when(
                $siteIds !== [],
                static fn (Builder $builder): Builder => $builder->whereIn('assignment.project_id', $siteIds),
            )
            ->when(
                $shiftIds !== [],
                static fn (Builder $builder): Builder => $builder->whereIn('assignment.work_schedule_id', $shiftIds),
            )
            ->whereDate('assignment.valid_from', '<=', $dayTo->format('Y-m-d'))
            ->where(static function (Builder $builder) use ($dayFrom): void {
                $builder->whereNull('assignment.valid_to')
                    ->orWhereDate('assignment.valid_to', '>=', $dayFrom->format('Y-m-d'));
            })
            ->select([
                'assignment.*',
                'employee.last_name',
                'employee.first_name',
                'employee.middle_name',
                'project.name as project_name',
                'schedule.hours_per_day',
                'schedule.name as schedule_name',
            ])
            ->orderBy('assignment.id')
            ->get();
        $employeeIds = $assignments->pluck('employee_id')->unique()->values()->all();
        $scheduleIds = $assignments->pluck('work_schedule_id')->filter()->unique()->values()->all();
        $scheduleDays = $scheduleIds === [] ? collect() : $this->connection
            ->table('workforce_work_schedule_days')
            ->where('organization_id', $scope->organizationId)
            ->whereIn('work_schedule_id', $scheduleIds)
            ->where('created_at', '<=', $query->asOf->format('Y-m-d H:i:sP'))
            ->whereBetween('work_date', [$dayFrom->format('Y-m-d'), $dayTo->format('Y-m-d')])
            ->get()
            ->keyBy(static fn (object $row): string => $row->work_schedule_id.':'.$row->work_date);
        $corrections = $employeeIds === [] ? collect() : $this->connection
            ->table('workforce_attendance_corrections')
            ->where('organization_id', $scope->organizationId)
            ->whereIn('employee_id', $employeeIds)
            ->where('created_at', '<=', $query->asOf->format('Y-m-d H:i:sP'))
            ->when(
                $projectFilterIds !== [],
                static fn (Builder $builder): Builder => $builder->whereIn('project_id', $projectFilterIds),
            )
            ->whereBetween('work_date', [$dayFrom->format('Y-m-d'), $dayTo->format('Y-m-d')])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->keyBy(static fn (object $row): string => $row->employee_id.':'.($row->project_id ?? 'none').':'.$row->work_date);
        $absences = $employeeIds === [] ? collect() : $this->connection
            ->table('workforce_absences')
            ->where('organization_id', $scope->organizationId)
            ->whereIn('employee_id', $employeeIds)
            ->where('created_at', '<=', $query->asOf->format('Y-m-d H:i:sP'))
            ->where('status', 'approved')
            ->whereNull('deleted_at')
            ->when(
                $absenceTypeIds !== [],
                static fn (Builder $builder): Builder => $builder->whereIn('absence_type_id', $absenceTypeIds),
            )
            ->whereDate('start_date', '<=', $dayTo->format('Y-m-d'))
            ->whereDate('end_date', '>=', $dayFrom->format('Y-m-d'))
            ->get()
            ->groupBy('employee_id');
        $scans = $employeeIds === [] ? collect() : $this->connection
            ->table('workforce_attendance_scan_events')
            ->where('organization_id', $scope->organizationId)
            ->whereIn('employee_id', $employeeIds)
            ->where('scanned_at', '<=', $query->asOf->format('Y-m-d H:i:sP'))
            ->where('result', 'confirmed')
            ->when(
                $projectFilterIds !== [],
                static fn (Builder $builder): Builder => $builder->whereIn('project_id', $projectFilterIds),
            )
            ->whereBetween('work_date', [$dayFrom->format('Y-m-d'), $dayTo->format('Y-m-d')])
            ->orderBy('scanned_at')
            ->orderBy('id')
            ->get()
            ->groupBy(static fn (object $row): string => $row->employee_id.':'.($row->project_id ?? 'none').':'.$row->work_date);
        $availableSiteIds = array_fill_keys(
            $assignments->pluck('project_id')->filter()->map(static fn (mixed $id): int => (int) $id)->all(),
            true,
        );
        $availableShiftIds = array_fill_keys(
            $assignments->pluck('work_schedule_id')->filter()->map(static fn (mixed $id): int => (int) $id)->all(),
            true,
        );
        if (array_diff($siteIds, array_keys($availableSiteIds)) !== []
            || array_diff($shiftIds, array_keys($availableShiftIds)) !== []) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }
        $rows = [];
        $attendanceWarnings = [];

        foreach ($this->effectiveAttendanceAssignments($assignments, $dayFrom, $dayTo) as $assignment) {
            foreach ($this->days($dayFrom, $dayTo) as $day) {
                $date = $day->format('Y-m-d');
                if ((string) $assignment->valid_from > $date
                    || ($assignment->valid_to !== null && (string) $assignment->valid_to < $date)) {
                    continue;
                }
                $scheduleDay = $assignment->work_schedule_id === null
                    ? null
                    : $scheduleDays->get($assignment->work_schedule_id.':'.$date);
                if ($scheduleDay === null) {
                    $attendanceWarnings[] = $assignment->work_schedule_id === null
                        ? 'WORK_SCHEDULE_MISSING'
                        : 'SCHEDULE_DAY_MISSING';
                }
                $eligible = $scheduleDay !== null
                    ? ((string) $scheduleDay->day_type === 'work' ? (string) $scheduleDay->planned_hours : '0')
                    : ($assignment->work_schedule_id === null ? '0' : (string) ($assignment->hours_per_day ?? 0));
                $key = $assignment->employee_id.':'.($assignment->project_id ?? 'none').':'.$date;
                $correction = $corrections->get($key);
                $dayScans = $scans->get($key, collect());
                $absence = $absences->get($assignment->employee_id, collect())->first(
                    static fn (object $row): bool => (string) $row->start_date <= $date && (string) $row->end_date >= $date,
                );
                $grains = $this->attendanceGrains(
                    $dayScans,
                    $assignment->project_id === null ? null : (int) $assignment->project_id,
                    $assignment->work_schedule_id === null ? null : (int) $assignment->work_schedule_id,
                    $correction !== null,
                );
                $eligiblePerGrain = $grains->count() === 1
                    ? BigDecimal::of($eligible)
                    : BigDecimal::of($eligible)->dividedBy($grains->count(), 8, RoundingMode::HalfUp);
                $remainingEligible = BigDecimal::of($eligible);

                foreach ($grains as $grainIndex => $grain) {
                    $shiftScans = $grain['scans'];
                    $metadata = $grain['metadata'];
                    $grainEligible = $grainIndex === $grains->count() - 1
                        ? $remainingEligible
                        : $eligiblePerGrain;
                    $remainingEligible = $remainingEligible->minus($grainEligible);
                    $siteId = $this->positiveMetadataId($metadata, 'site_id')
                        ?? ($assignment->project_id === null ? null : (int) $assignment->project_id);
                    $shiftId = $this->positiveMetadataId($metadata, 'shift_id')
                        ?? ($assignment->work_schedule_id === null ? null : (int) $assignment->work_schedule_id);
                    if (($siteIds !== [] && ($siteId === null || ! in_array($siteId, $siteIds, true)))
                        || ($shiftIds !== [] && ($shiftId === null || ! in_array($shiftId, $shiftIds, true)))) {
                        continue;
                    }
                    $present = $correction !== null && (string) $correction->status === 'at_work'
                        ? (string) ($correction->hours ?? $grainEligible)
                        : ($shiftScans->isEmpty()
                            ? '0'
                            : $this->decimalMetadata($metadata, 'present_hours', (string) $grainEligible));
                    $absenceHours = $absence === null ? '0' : (string) $grainEligible;
                    $overtime = BigDecimal::of($present)->minus($grainEligible);
                    if ($overtime->isLessThan(BigDecimal::zero())) {
                        $overtime = BigDecimal::zero();
                    }
                    $metrics = $this->attendanceFormula->calculate(
                        eligibleHours: (string) $grainEligible,
                        presentHours: $present,
                        approvedAbsenceHours: $absenceHours,
                        overtimeHours: (string) $overtime,
                        lateHours: $this->decimalMetadata($metadata, 'late_hours', '0'),
                        earlyHours: $this->decimalMetadata($metadata, 'early_hours', '0'),
                        corrected: $correction !== null,
                    );
                    $sourceRefs = [
                        ['type' => 'assignment', 'id' => (int) $assignment->id],
                    ];
                    if ($scheduleDay !== null) {
                        $sourceRefs[] = ['type' => 'schedule_day', 'id' => (int) $scheduleDay->id];
                    }
                    if ($absence !== null) {
                        $sourceRefs[] = ['type' => 'approved_absence', 'id' => (int) $absence->id];
                        $sourceRefs[] = ['type' => 'absence_type', 'id' => (int) $absence->absence_type_id];
                    }
                    foreach ($shiftScans as $scan) {
                        $sourceRefs[] = ['type' => 'attendance_scan', 'id' => (int) $scan->id];
                    }
                    $auditRefs = $correction === null ? [] : [
                        ['type' => 'attendance_correction', 'id' => (int) $correction->id],
                    ];
                    $row = [
                        'row_key' => hash('sha256', implode('|', [
                            $date,
                            $assignment->employee_id,
                            $assignment->project_id ?? 'none',
                            $siteId ?? 'none',
                            $shiftId ?? 'none',
                            $assignment->id,
                        ])),
                        'work_date' => $date,
                        'employee_id' => (int) $assignment->employee_id,
                        'employee_name' => trim(implode(' ', array_filter([
                            $assignment->last_name,
                            $assignment->first_name,
                            $assignment->middle_name,
                        ]))),
                        'project_id' => $assignment->project_id === null ? null : (int) $assignment->project_id,
                        'project_name' => $assignment->project_name,
                        'site_id' => $siteId,
                        'site_name' => $this->metadataLabel($metadata, 'site_name')
                            ?? $assignment->project_name,
                        'shift_id' => $shiftId,
                        'shift' => $this->metadataLabel($metadata, 'shift')
                            ?? $assignment->schedule_name,
                        'close_version' => hash('sha256', CanonicalJson::encode([
                            'date' => $date,
                            'employee_id' => (int) $assignment->employee_id,
                            'project_id' => $assignment->project_id === null
                                ? null
                                : (int) $assignment->project_id,
                            'site_id' => $siteId,
                            'shift_id' => $shiftId,
                            'eligible_hours' => $metrics->eligibleHours,
                            'present_hours' => $metrics->presentHours,
                            'approved_absence_hours' => $metrics->approvedAbsenceHours,
                            'unexplained_absence_hours' => $metrics->unexplainedAbsenceHours,
                            'overtime_hours' => $metrics->overtimeHours,
                            'late_hours' => $metrics->lateHours,
                            'early_hours' => $metrics->earlyHours,
                            'source_refs' => $sourceRefs,
                            'audit_refs' => $auditRefs,
                        ])),
                        'status' => $metrics->violation ? 'unexplained_absence' : 'covered',
                        'eligible_hours' => $metrics->eligibleHours,
                        'present_hours' => $metrics->presentHours,
                        'approved_absence_hours' => $metrics->approvedAbsenceHours,
                        'unexplained_absence_hours' => $metrics->unexplainedAbsenceHours,
                        'absence_hours' => $metrics->unexplainedAbsenceHours,
                        'overtime_hours' => $metrics->overtimeHours,
                        'late_hours' => $metrics->lateHours,
                        'early_hours' => $metrics->earlyHours,
                        'execution_percent' => $metrics->executionPercent,
                        'correction_rate' => $metrics->correctionRate,
                        'violation' => $metrics->violation,
                        'source_refs' => $sourceRefs,
                        'audit_refs' => $auditRefs,
                    ];
                    if ($statuses === [] || in_array($row['status'], $statuses, true)) {
                        $this->assertMaterializedRowScope($scope, $row);
                        $rows[] = $row;
                    }
                }
            }
        }

        return $this->persist(
            scope: $scope,
            query: $query,
            code: 'attendance_execution',
            formulaVersion: self::ATTENDANCE_FORMULA,
            rowTable: 'attendance_execution_snapshot_rows',
            rows: $rows,
            totals: $this->attendanceTotals($rows),
            rowSchema: $this->attendanceSchema(),
            warnings: array_values(array_unique($attendanceWarnings)),
        );
    }

    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
    {
        $record = $this->snapshot($context, $snapshot);
        $warnings = $this->json($record->warnings);
        $rowSchema = $this->json($record->row_schema);
        if ($record->report_code === 'attendance_execution' && ! $context->visibility->canViewAudit) {
            $rowSchema = array_values(array_filter(
                $rowSchema,
                static fn (array $column): bool => ($column['id'] ?? null) !== 'audit_refs',
            ));
        }
        if ($record->report_code === 'workforce_capacity' && ! $context->visibility->canViewSensitive) {
            $rowSchema = array_values(array_filter(
                $rowSchema,
                static fn (array $column): bool => ! in_array(
                    $column['id'] ?? null,
                    ['rate', 'currency', 'period_cost_run_rate'],
                    true,
                ),
            ));
        }

        return new ReportResult(
            metadata: new ReportResultMetadata(
                snapshot: $snapshot,
                rowCount: (int) $record->row_count,
                generatedAt: $snapshot->generatedAt,
                staleAt: $snapshot->staleAt,
            ),
            totals: $this->json($record->totals),
            freshness: ReportFreshnessStatus::from((string) $record->freshness_status),
            quality: $this->quality($record, $warnings),
            provenance: $this->provenance($record, $snapshot),
            rowSchema: $rowSchema,
            capabilities: [
                'keyset' => true,
                'drill_down' => true,
                'same_snapshot_export' => true,
            ],
        );
    }

    public function page(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        ?ReportCursor $cursor,
        int $limit,
    ): ReportPage {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('workforce_report_page_limit_invalid');
        }
        $record = $this->snapshot($context, $snapshot);
        $code = (string) $record->report_code;
        $this->assertSort($code, $sort);
        $table = $code === 'workforce_capacity'
            ? 'workforce_capacity_snapshot_rows'
            : 'attendance_execution_snapshot_rows';
        $field = $sort->field === 'absence_hours' ? 'unexplained_absence_hours' : $sort->field;
        $builder = $this->connection->table($table)
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id);
        $position = $cursor === null
            ? null
            : $this->cursorKeyset($cursor, $snapshot, $sort, (string) $record->query_hash);
        if ($position !== null) {
            $this->applyCursor($builder, $field, $sort, $position);
        }
        $direction = $sort->direction === ReportSortDirection::ASC ? 'asc' : 'desc';
        $records = $builder->orderByRaw("CASE WHEN {$field} IS NULL THEN 1 ELSE 0 END ASC")
            ->orderBy($field, $direction)
            ->orderBy('row_key', $direction)
            ->limit($limit + 1)
            ->get();
        $hasMore = $records->count() > $limit;
        $records = $records->take($limit)->values();
        $rows = $records->map(
            fn (object $row): array => $this->visibleRow($this->json($row->row_payload), $code, $context),
        )->all();

        return new ReportPage(
            rows: $rows,
            totals: $this->json($record->totals),
            freshness: ReportFreshnessStatus::from((string) $record->freshness_status),
            quality: $this->quality($record, $this->json($record->warnings)),
            nextCursor: null,
            limit: $limit,
            hasMore: $hasMore,
            sort: $sort,
        );
    }

    public function cursor(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        int $chunkSize,
    ): iterable {
        if ($chunkSize < 1 || $chunkSize > 5000) {
            throw new InvalidArgumentException('workforce_report_chunk_size_invalid');
        }
        $record = $this->snapshot($context, $snapshot);
        $code = (string) $record->report_code;
        $this->assertSort($code, $sort);
        $table = $code === 'workforce_capacity'
            ? 'workforce_capacity_snapshot_rows'
            : 'attendance_execution_snapshot_rows';
        $field = $sort->field === 'absence_hours' ? 'unexplained_absence_hours' : $sort->field;
        $direction = $sort->direction === ReportSortDirection::ASC ? 'asc' : 'desc';
        $position = null;
        do {
            $builder = $this->connection->table($table)
                ->where('organization_id', $context->scope->organizationId)
                ->where('snapshot_id', $snapshot->id);
            if ($position !== null) {
                $this->applyCursor($builder, $field, $sort, $position);
            }
            $records = $builder->orderByRaw("CASE WHEN {$field} IS NULL THEN 1 ELSE 0 END ASC")
                ->orderBy($field, $direction)
                ->orderBy('row_key', $direction)
                ->limit($chunkSize)
                ->get();
            foreach ($records as $row) {
                yield [
                    'query_hash' => (string) $record->query_hash,
                    'row_key' => (string) $row->row_key,
                    'snapshot_id' => $snapshot->id,
                    'source_hash' => $snapshot->sourceHash->value,
                    'values' => $this->visibleRow($this->json($row->row_payload), $code, $context),
                ];
            }
            $last = $records->last();
            $position = $last === null
                ? null
                : new ReportCursorKeyset($last->{$field}, (string) $last->row_key);
        } while ($records->count() === $chunkSize);
    }

    public function drillDown(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportDrillDownInput $input,
    ): ReportDrillDownResult {
        $record = $this->snapshot($context, $snapshot);
        $code = (string) $record->report_code;
        $table = $code === 'workforce_capacity'
            ? 'workforce_capacity_snapshot_rows'
            : 'attendance_execution_snapshot_rows';
        $row = $this->connection->table($table)
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id)
            ->where('row_key', $input->cell->rowKey)
            ->first();
        if ($row === null) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }
        $payload = $this->visibleRow($this->json($row->row_payload), $code, $context);
        $this->assertProjectAccess($context->scope, $payload['project_id'] ?? null);
        $sourceRefs = $payload['source_refs'] ?? [];
        if ($code === 'attendance_execution' && $context->visibility->canViewAudit) {
            $sourceRefs = [...$sourceRefs, ...($payload['audit_refs'] ?? [])];
        }
        $rows = array_map(
            static fn (array $ref, int $index): array => [
                'row_key' => hash('sha256', $input->cell->rowKey.'|'.$index),
                'source_type' => $ref['type'],
                'source_id' => $ref['id'],
            ],
            $sourceRefs,
            array_keys($sourceRefs),
        );
        $offset = $this->drillDownOffset($input->cursor, $input->cell->rowKey);
        $nextOffset = $offset + $input->limit;

        return new ReportDrillDownResult(
            array_slice($rows, $offset, $input->limit),
            $nextOffset < count($rows)
                ? $this->drillDownCursor($input->cell->rowKey, $nextOffset)
                : null,
            [],
        );
    }

    private function persist(
        ReportScope $scope,
        ReportQuery $query,
        string $code,
        string $formulaVersion,
        string $rowTable,
        array $rows,
        array $totals,
        array $rowSchema,
        array $warnings,
    ): ReportSnapshotRef {
        $id = (string) Str::ulid();
        $generatedAt = new DateTimeImmutable;
        $staleAt = $generatedAt->add(new DateInterval($code === 'attendance_execution' ? 'PT15M' : 'P1D'));
        $sourceManifest = $this->sourceManifest($code, $rows);
        $sourceHash = new Sha256Hash(hash('sha256', CanonicalJson::encode([
            'code' => $code,
            'organization_id' => $scope->organizationId,
            'query_hash' => $query->queryHash->value,
            'rows' => $rows,
            'schema_version' => self::SCHEMA_VERSION,
        ])));
        $coverageDenominator = count($rows);
        $coverageNumerator = count(array_filter(
            $rows,
            static function (array $row) use ($code): bool {
                if ($code === 'workforce_capacity') {
                    return ($row['quality_warnings'] ?? []) === [];
                }

                foreach ($row['source_refs'] ?? [] as $ref) {
                    if (($ref['type'] ?? null) === 'schedule_day') {
                        return true;
                    }
                }

                return false;
            },
        ));
        $totals['_coverage'] = [
            'numerator' => $coverageNumerator,
            'denominator' => $coverageDenominator,
        ];
        if ($coverageDenominator === 0) {
            $warnings[] = 'REPORT_SOURCE_EMPTY';
        }
        $warnings = array_values(array_unique($warnings));
        $quality = $coverageDenominator > 0
            && $coverageNumerator === $coverageDenominator
            && $warnings === []
                ? 'complete'
                : 'partial';
        $reconciliation = $coverageDenominator > 0 && $coverageNumerator === $coverageDenominator
            ? 'matched'
            : 'mismatch';
        $this->connection->transaction(function () use (
            $id,
            $scope,
            $query,
            $code,
            $formulaVersion,
            $sourceHash,
            $totals,
            $rowSchema,
            $warnings,
            $rows,
            $rowTable,
            $generatedAt,
            $staleAt,
            $quality,
            $reconciliation,
            $sourceManifest,
        ): void {
            $timestamp = $generatedAt->format('Y-m-d H:i:sP');
            $this->connection->table('workforce_report_snapshots')->insert([
                'id' => $id,
                'organization_id' => $scope->organizationId,
                'report_code' => $code,
                'definition_hash' => $query->definition->definitionHash->value,
                'query_hash' => $query->queryHash->value,
                'source_hash' => $sourceHash->value,
                'formula_version' => $formulaVersion,
                'source_schema_version' => self::SCHEMA_VERSION,
                'freshness_status' => 'fresh',
                'quality_status' => $quality,
                'reconciliation_status' => $reconciliation,
                'totals' => CanonicalJson::encode($totals),
                'row_schema' => CanonicalJson::encode($rowSchema),
                'warnings' => CanonicalJson::encode($warnings),
                'source_refs' => CanonicalJson::encode($sourceManifest),
                'row_count' => count($rows),
                'generated_at' => $timestamp,
                'stale_at' => $staleAt->format('Y-m-d H:i:sP'),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            foreach (array_chunk($rows, 500) as $chunk) {
                $this->connection->table($rowTable)->insert(array_map(
                    static function (array $row) use ($id, $scope): array {
                        $payload = $row;
                        unset($row['absence_hours']);
                        foreach (['source_refs', 'audit_refs', 'quality_warnings'] as $jsonColumn) {
                            if (array_key_exists($jsonColumn, $row)) {
                                $row[$jsonColumn] = CanonicalJson::encode($row[$jsonColumn]);
                            }
                        }
                        $row['organization_id'] = $scope->organizationId;
                        $row['snapshot_id'] = $id;
                        $row['row_payload'] = CanonicalJson::encode($payload);

                        return $row;
                    },
                    $chunk,
                ));
            }
        });

        return new ReportSnapshotRef(
            kind: $code,
            id: $id,
            scope: $scope,
            definitionHash: $query->definition->definitionHash,
            formulaVersion: $formulaVersion,
            sourceHash: $sourceHash,
            generatedAt: $generatedAt,
            staleAt: $staleAt,
            watermarks: array_column($sourceManifest, 'watermark', 'snapshot_id'),
            classification: ReportSnapshotClassification::OPERATIONAL,
            seal: null,
        );
    }

    private function snapshot(ReportExecutionContext $context, ReportSnapshotRef $snapshot): object
    {
        if ($snapshot->scope->organizationId !== $context->scope->organizationId) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }
        $record = $this->connection->table('workforce_report_snapshots')
            ->where('organization_id', $context->scope->organizationId)
            ->where('id', $snapshot->id)
            ->where('source_hash', $snapshot->sourceHash->value)
            ->first();
        if ($record === null) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }
        if (! in_array($snapshot->kind, ['workforce_capacity', 'attendance_execution'], true)
            || (string) $record->report_code !== $snapshot->kind) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }

        return $record;
    }

    private function quality(object $record, array $warningCodes): ReportQuality
    {
        $totals = $this->json($record->totals);
        $coverage = is_array($totals['_coverage'] ?? null) ? $totals['_coverage'] : [];
        $numerator = max(0, (int) ($coverage['numerator'] ?? 0));
        $denominator = max(0, (int) ($coverage['denominator'] ?? 0));
        $warnings = array_map(
            static fn (string $code): ReportWarning => new ReportWarning(
                code: $code,
                severity: ReportWarningSeverity::WARNING,
                metric: null,
                affectedRowCount: (int) $record->row_count,
            ),
            $warningCodes,
        );

        return new ReportQuality(
            status: ReportQualityStatus::from((string) $record->quality_status),
            coverage: new ReportCoverage(
                numerator: (string) $numerator,
                denominator: (string) $denominator,
                ratio: $denominator === 0
                    ? null
                    : (string) BigDecimal::of($numerator)
                        ->dividedBy($denominator, 8, RoundingMode::HalfUp),
            ),
            warnings: $warnings,
            unmatchedCount: max($denominator - $numerator, 0),
            reconciliation: ReportReconciliationStatus::from((string) $record->reconciliation_status),
            unknownMetrics: $warningCodes === []
                ? []
                : ((string) $record->report_code === 'attendance_execution'
                    ? ['eligible_hours', 'execution_percent']
                    : ['period_cost_run_rate']),
            excludedSources: [],
        );
    }

    private function provenance(object $record, ReportSnapshotRef $snapshot): ReportProvenance
    {
        return new ReportProvenance(
            sourceOfTruth: 'workforce_owner_snapshot',
            sourceRefs: array_map(
                static fn (array $ref): ReportSourceRef => new ReportSourceRef(
                    source: (string) $ref['source'],
                    snapshotKind: (string) $ref['snapshot_kind'],
                    snapshotId: (string) $ref['snapshot_id'],
                    schemaVersion: (string) $ref['schema_version'],
                    watermark: (string) $ref['watermark'],
                    rowCount: (int) $ref['row_count'],
                    hash: new Sha256Hash((string) $ref['hash']),
                ),
                $this->json($record->source_refs),
            ),
            sourceHash: $snapshot->sourceHash,
            externalConfirmationRole: null,
        );
    }

    private function sourceManifest(string $code, array $rows): array
    {
        $byType = [];
        foreach ($rows as $row) {
            $rowHash = hash('sha256', CanonicalJson::encode($row));
            foreach ($row['source_refs'] as $ref) {
                $type = (string) $ref['type'];
                $byType[$type][(int) $ref['id']][$rowHash] = $rowHash;
            }
        }
        if ($byType === []) {
            $byType[$code.'_source'] = [];
        }
        ksort($byType, SORT_STRING);

        return array_map(static function (string $type, array $sources): array {
            $ids = array_keys($sources);
            sort($ids, SORT_NUMERIC);
            $identities = array_map(static function (int $id) use ($sources): array {
                $rowHashes = array_values($sources[$id]);
                sort($rowHashes, SORT_STRING);

                return ['id' => $id, 'row_hashes' => $rowHashes];
            }, $ids);

            return [
                'source' => $type,
                'snapshot_kind' => 'owner_rows',
                'snapshot_id' => 'rows_'.$type,
                'schema_version' => 'v1',
                'watermark' => 'max_id_'.($ids === [] ? 0 : max($ids)),
                'row_count' => count($ids),
                'hash' => hash('sha256', CanonicalJson::encode($identities)),
            ];
        }, array_keys($byType), array_values($byType));
    }

    private function drillDownOffset(?string $cursor, string $rowKey): int
    {
        if ($cursor === null) {
            return 0;
        }
        if (preg_match('/^(0|[1-9][0-9]*)\.([a-f0-9]{64})$/D', $cursor, $matches) !== 1
            || ! hash_equals(hash('sha256', $rowKey.'|'.$matches[1]), $matches[2])) {
            throw new InvalidArgumentException('workforce_report_drill_down_cursor_invalid');
        }

        return (int) $matches[1];
    }

    private function drillDownCursor(string $rowKey, int $offset): string
    {
        return $offset.'.'.hash('sha256', $rowKey.'|'.$offset);
    }

    private function visibleRow(array $row, string $code, ReportExecutionContext $context): array
    {
        if ($code === 'attendance_execution' && ! $context->visibility->canViewAudit) {
            unset($row['audit_refs']);
        }
        $this->assertProjectAccess($context->scope, $row['project_id'] ?? null);
        foreach ($row['source_refs'] ?? [] as $ref) {
            if (is_array($ref) && isset($ref['type'], $ref['id'])) {
                $this->assertScopedResource(
                    $context->scope,
                    (string) $ref['type'],
                    (int) $ref['id'],
                    isset($row['project_id']) ? (int) $row['project_id'] : null,
                );
            }
        }
        if ($code === 'attendance_execution' && $context->visibility->canViewAudit) {
            foreach ($row['audit_refs'] ?? [] as $ref) {
                if (is_array($ref) && isset($ref['type'], $ref['id'])) {
                    $this->assertScopedResource(
                        $context->scope,
                        (string) $ref['type'],
                        (int) $ref['id'],
                        isset($row['project_id']) ? (int) $row['project_id'] : null,
                    );
                }
            }
        }
        if ($code === 'workforce_capacity' && ! $context->visibility->canViewSensitive) {
            unset($row['rate'], $row['currency'], $row['period_cost_run_rate']);
            $row['source_refs'] = array_values(array_filter(
                $row['source_refs'],
                static fn (array $ref): bool => ($ref['type'] ?? null) !== 'labor_rate_version',
            ));
        }

        return $row;
    }

    private function assertProjectAccess(ReportScope $scope, mixed $projectId): void
    {
        $resourceProjectIds = array_map(
            static fn (object $resource): int => $resource->id,
            array_values(array_filter(
                $scope->resources,
                static fn (object $resource): bool => $resource->kind === 'project',
            )),
        );
        if ($projectId === null && ($scope->projectIds !== [] || $resourceProjectIds !== [])) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }
        if ($projectId !== null
            && ($scope->projectIds !== [] && ! in_array((int) $projectId, $scope->projectIds, true)
                || $resourceProjectIds !== [] && ! in_array((int) $projectId, $resourceProjectIds, true))) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }
    }

    private function assertScope(ReportScope $scope, ReportQuery $query): void
    {
        if ($scope->organizationId !== $query->scope->organizationId) {
            throw new InvalidArgumentException('workforce_report_scope_invalid');
        }
    }

    private function assertSort(string $code, ReportWindowSort $sort): void
    {
        if (! in_array($sort->field, self::SORTS[$code] ?? [], true)) {
            throw new InvalidArgumentException('workforce_report_sort_invalid');
        }
    }

    private function cursorKeyset(
        ReportCursor $cursor,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        string $queryHash,
    ): ReportCursorKeyset {
        if ($cursor->sourceHash->value !== $snapshot->sourceHash->value
            || $cursor->queryHash->value !== $queryHash
            || $cursor->sort->field !== $sort->field
            || $cursor->sort->direction !== $sort->direction) {
            throw new InvalidArgumentException('workforce_report_cursor_invalid');
        }

        return $cursor->keyset;
    }

    private function applyCursor(
        Builder $builder,
        string $field,
        ReportWindowSort $sort,
        ReportCursorKeyset $position,
    ): void {
        $operator = $sort->direction === ReportSortDirection::ASC ? '>' : '<';
        if ($position->lastSortValue === null) {
            $builder->whereNull($field)->where('row_key', $operator, $position->lastStableRowKey);

            return;
        }
        $builder->where(static function (Builder $nested) use ($field, $operator, $position): void {
            $nested->where($field, $operator, $position->lastSortValue)
                ->orWhere(static function (Builder $same) use ($field, $operator, $position): void {
                    $same->where($field, $position->lastSortValue)
                        ->where('row_key', $operator, $position->lastStableRowKey);
                })
                ->orWhereNull($field);
        });
    }

    private function effectiveAttendanceAssignments(
        iterable $assignments,
        DateTimeImmutable $dayFrom,
        DateTimeImmutable $dayTo,
    ): array {
        $unique = [];
        foreach ($assignments as $assignment) {
            $unique[(int) $assignment->id] = $assignment;
        }
        $assignments = array_values($unique);
        $byEmployee = [];
        foreach ($assignments as $assignment) {
            $byEmployee[(int) $assignment->employee_id][] = $assignment;
        }
        foreach ($byEmployee as $employeeAssignments) {
            foreach ($this->days($dayFrom, $dayTo) as $day) {
                $date = $day->format('Y-m-d');
                $active = array_filter(
                    $employeeAssignments,
                    static fn (object $assignment): bool => (string) $assignment->valid_from <= $date
                        && ($assignment->valid_to === null || $date <= (string) $assignment->valid_to),
                );
                if (count($active) > 1) {
                    throw new DomainException('WORKFORCE_ASSIGNMENT_OVERLAP');
                }
            }
        }

        usort(
            $assignments,
            static fn (object $left, object $right): int => (int) $left->id <=> (int) $right->id,
        );

        return $assignments;
    }

    private function capacitySchedule(
        iterable $assignments,
        iterable $scheduleDays,
        DateTimeImmutable $month,
        BigDecimal $plannedFte,
    ): array {
        $scheduleIds = [];
        foreach ($assignments as $assignment) {
            if ($assignment->work_schedule_id !== null) {
                $scheduleIds[(int) $assignment->work_schedule_id] = (int) $assignment->work_schedule_id;
            }
        }
        if (count($scheduleIds) !== 1) {
            return ['0.00', ['MISSING_CAPACITY_SCHEDULE'], []];
        }

        $scheduleId = array_values($scheduleIds)[0];
        $hoursPerFte = BigDecimal::zero();
        $refs = [];
        foreach ($scheduleDays as $day) {
            if ((int) $day->work_schedule_id !== $scheduleId
                || substr((string) $day->work_date, 0, 7) !== $month->format('Y-m')
                || (string) $day->day_type !== 'work') {
                continue;
            }
            $hoursPerFte = $hoursPerFte->plus((string) $day->planned_hours);
            $refs[] = ['type' => 'schedule_day', 'id' => (int) $day->id];
        }
        if ($refs === []) {
            return ['0.00', ['MISSING_CAPACITY_SCHEDULE'], []];
        }

        return [
            (string) $hoursPerFte->multipliedBy($plannedFte)->toScale(2, RoundingMode::HalfUp),
            [],
            $refs,
        ];
    }

    private function effectiveRate(
        Collection $ratesByEmployee,
        int $employeeId,
        DateTimeImmutable $asOf,
    ): ?object {
        $date = $asOf->format('Y-m-d');
        $rates = $ratesByEmployee->get($employeeId, collect())->filter(
            static fn (object $rate): bool => (string) $rate->valid_from <= $date
                && ($rate->valid_to_exclusive === null || $date < (string) $rate->valid_to_exclusive),
        )->unique(
            static fn (object $rate): string => implode(':', [
                $rate->amount,
                $rate->currency ?? 'none',
                $rate->rate_type,
                $rate->valid_from,
                $rate->valid_to_exclusive ?? 'open',
                $rate->version,
            ]),
        )->values();
        if ($rates->count() > 1) {
            throw new DomainException('LABOR_RATE_OVERLAP');
        }
        $rate = $rates->first();
        if ($rate === null) {
            return null;
        }
        if (BigDecimal::of((string) $rate->amount)->isLessThan(BigDecimal::zero())
            || ! is_string($rate->rate_type)
            || trim($rate->rate_type) === ''
            || ($rate->currency !== null
                && (! is_string($rate->currency) || preg_match('/^[A-Z]{3}$/D', $rate->currency) !== 1))) {
            throw new DomainException('LABOR_RATE_INVALID');
        }

        return $rate;
    }

    private function positiveMetadataId(array $metadata, string $field): ?int
    {
        $value = $metadata[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_int($value) || $value < 1) {
            throw new DomainException('ATTENDANCE_SOURCE_METADATA_INVALID');
        }

        return $value;
    }

    private function decimalMetadata(array $metadata, string $field, string $default): string
    {
        $value = $metadata[$field] ?? $default;
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new DomainException('ATTENDANCE_SOURCE_METADATA_INVALID');
        }
        $decimal = BigDecimal::of((string) $value);
        if ($decimal->isLessThan(BigDecimal::zero())) {
            throw new DomainException('ATTENDANCE_SOURCE_METADATA_INVALID');
        }

        return (string) $decimal;
    }

    private function metadataLabel(array $metadata, string $field): ?string
    {
        $value = $metadata[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || trim($value) === '') {
            throw new DomainException('ATTENDANCE_SOURCE_METADATA_INVALID');
        }

        return trim($value);
    }

    private function assertOrganizationIds(
        string $table,
        ReportScope $scope,
        array $ids,
    ): void {
        if ($ids === []) {
            return;
        }
        $found = $this->connection->table($table)
            ->where('organization_id', $scope->organizationId)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        sort($found, SORT_NUMERIC);
        $expected = $ids;
        sort($expected, SORT_NUMERIC);
        if ($found !== $expected) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }
    }

    private function assertMaterializedRowScope(ReportScope $scope, array $row): void
    {
        $projectId = isset($row['project_id']) ? (int) $row['project_id'] : null;
        $this->assertProjectAccess($scope, $projectId);
        foreach ($row['source_refs'] as $ref) {
            $this->assertScopedResource(
                $scope,
                (string) $ref['type'],
                (int) $ref['id'],
                $projectId,
            );
        }
        foreach ([
            'project' => $row['project_id'] ?? null,
            'employee' => $row['employee_id'] ?? null,
            'department' => $row['department_id'] ?? null,
            'position' => $row['position_id'] ?? null,
            'site' => $row['site_id'] ?? null,
            'shift' => $row['shift_id'] ?? null,
        ] as $kind => $id) {
            if ($id !== null) {
                $this->assertScopedResource($scope, $kind, (int) $id, $projectId);
            }
        }
        foreach ($row['audit_refs'] ?? [] as $ref) {
            $this->assertScopedResource(
                $scope,
                (string) $ref['type'],
                (int) $ref['id'],
                $projectId,
            );
        }
    }

    private function assertScopedResource(
        ReportScope $scope,
        string $kind,
        int $id,
        ?int $projectId,
    ): void {
        $restricted = array_values(array_filter(
            $scope->resources,
            static fn (object $resource): bool => $resource->kind === $kind,
        ));
        if ($restricted === []) {
            return;
        }
        foreach ($restricted as $resource) {
            if ($resource->id === $id
                && ($resource->projectId === null
                    || ($projectId !== null && $resource->projectId === $projectId))) {
                return;
            }
        }

        throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
    }

    private function authorizedIds(ReportScope $scope, string $kind, array $requested): array
    {
        $allowed = array_values(array_unique(array_map(
            static fn (object $resource): int => $resource->id,
            array_filter(
                $scope->resources,
                static fn (object $resource): bool => $resource->kind === $kind,
            ),
        )));
        sort($allowed, SORT_NUMERIC);
        if ($allowed === []) {
            return $requested;
        }
        if ($requested === []) {
            return $allowed;
        }
        if (array_diff($requested, $allowed) !== []) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }

        return $requested;
    }

    private function attendanceGrains(
        Collection $scans,
        ?int $fallbackSiteId,
        ?int $fallbackShiftId,
        bool $hasDayLevelCorrection = false,
    ): Collection {
        if ($scans->isEmpty()) {
            return collect([['scans' => collect(), 'metadata' => []]]);
        }

        $grains = $scans
            ->groupBy(function (object $scan) use ($fallbackSiteId, $fallbackShiftId): string {
                $metadata = $scan->metadata === null ? [] : $this->json($scan->metadata);

                return implode(':', [
                    $this->positiveMetadataId($metadata, 'site_id') ?? $fallbackSiteId ?? 'none',
                    $this->positiveMetadataId($metadata, 'shift_id') ?? $fallbackShiftId ?? 'none',
                ]);
            })
            ->map(function (Collection $shiftScans): array {
                $last = $shiftScans->last();

                return [
                    'scans' => $shiftScans->values(),
                    'metadata' => $last->metadata === null ? [] : $this->json($last->metadata),
                ];
            })
            ->values();

        if ($hasDayLevelCorrection && $grains->count() > 1) {
            throw new DomainException('ATTENDANCE_CORRECTION_GRAIN_AMBIGUOUS');
        }

        return $grains;
    }

    private function assertNoPostAsOfMutations(
        ReportScope $scope,
        ReportQuery $query,
        array $tables,
    ): void {
        $asOf = $query->asOf->format('Y-m-d H:i:sP');
        $resourceProjectIds = array_map(
            static fn (object $resource): int => $resource->id,
            array_values(array_filter(
                $scope->resources,
                static fn (object $resource): bool => $resource->kind === 'project',
            )),
        );
        $projectIds = array_values(array_unique([...$scope->projectIds, ...$resourceProjectIds]));
        foreach ($tables as $table) {
            if ($this->connection->table('workforce_report_owner_facts')
                ->where('organization_id', $scope->organizationId)
                ->where('source_table', $table)
                ->where('recorded_at', '>', $asOf)
                ->when(
                    $projectIds !== [],
                    static fn (Builder $builder): Builder => $builder->where(
                        static fn (Builder $scopeBuilder): Builder => $scopeBuilder
                            ->whereNull('project_id')
                            ->orWhereIn('project_id', $projectIds),
                    ),
                )
                ->exists()) {
                throw new DomainException('WORKFORCE_HISTORICAL_SOURCE_UNAVAILABLE');
            }
        }
    }

    private function ids(ReportQuery $query, string $filter): array
    {
        $values = $query->filters->values[$filter] ?? [];
        if (! is_array($values) || ! array_is_list($values)) {
            throw new InvalidArgumentException('workforce_report_filter_invalid');
        }
        foreach ($values as $value) {
            if (! is_int($value) || $value < 1) {
                throw new InvalidArgumentException('workforce_report_filter_invalid');
            }
        }

        return array_values(array_unique($values));
    }

    private function strings(ReportQuery $query, string $filter): array
    {
        $values = $query->filters->values[$filter] ?? [];
        if (! is_array($values) || ! array_is_list($values)) {
            throw new InvalidArgumentException('workforce_report_filter_invalid');
        }
        foreach ($values as $value) {
            if (! is_string($value) || $value === '') {
                throw new InvalidArgumentException('workforce_report_filter_invalid');
            }
        }

        return array_values(array_unique($values));
    }

    private function projectIds(
        ReportScope $scope,
        ReportQuery $query,
        bool $applyRequestedFilter = true,
    ): array {
        $requested = $this->ids($query, 'project_ids');
        $resourceIds = array_values(array_unique(array_map(
            static fn (object $resource): int => $resource->id,
            array_filter(
                $scope->resources,
                static fn (object $resource): bool => $resource->kind === 'project',
            ),
        )));
        $allowed = $scope->projectIds;
        if ($resourceIds !== []) {
            $allowed = $allowed === [] ? $resourceIds : array_values(array_intersect($allowed, $resourceIds));
        }
        $restricted = $scope->projectIds !== [] || $resourceIds !== [];
        if ($restricted && $allowed === []) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }
        if (! $applyRequestedFilter || $requested === []) {
            $this->assertOrganizationIds('projects', $scope, $allowed);

            return $allowed;
        }
        if ($restricted && array_diff($requested, $allowed) !== []) {
            throw new DomainException('REPORT_FILTER_VALUE_NOT_FOUND');
        }
        $this->assertOrganizationIds('projects', $scope, $requested);

        return $requested;
    }

    private function monthRange(ReportQuery $query): array
    {
        $from = $query->filters->values['month_from'] ?? null;
        $to = $query->filters->values['month_to'] ?? null;
        if (! is_string($from) || ! is_string($to)) {
            throw new InvalidArgumentException('workforce_capacity_period_required');
        }
        $monthFrom = new DateTimeImmutable($from.'-01');
        $monthTo = new DateTimeImmutable($to.'-01');
        $asOfMonth = new DateTimeImmutable($query->asOf->format('Y-m-01'));
        if ($monthFrom > $monthTo || $monthTo > $asOfMonth) {
            throw new InvalidArgumentException('workforce_capacity_period_invalid');
        }

        return [$monthFrom, $monthTo];
    }

    private function dayRange(ReportQuery $query): array
    {
        $from = $query->filters->values['day_from'] ?? null;
        $to = $query->filters->values['day_to'] ?? null;
        if (! is_string($from) || ! is_string($to)) {
            throw new InvalidArgumentException('attendance_execution_period_required');
        }
        $dayFrom = new DateTimeImmutable($from);
        $dayTo = new DateTimeImmutable($to);
        if ($dayFrom > $dayTo || $dayTo > $query->asOf) {
            throw new InvalidArgumentException('attendance_execution_period_invalid');
        }

        return [$dayFrom, $dayTo];
    }

    private function months(DateTimeImmutable $from, DateTimeImmutable $to): iterable
    {
        return new DatePeriod($from, new DateInterval('P1M'), $to->modify('+1 month'));
    }

    private function days(DateTimeImmutable $from, DateTimeImmutable $to): iterable
    {
        return new DatePeriod($from, new DateInterval('P1D'), $to->modify('+1 day'));
    }

    private function capacityTotals(array $rows): array
    {
        $totals = [];
        foreach ($rows as $row) {
            $month = $row['month'];
            $totals[$month] ??= [
                'planned_fte' => BigDecimal::zero(),
                'assigned_fte' => BigDecimal::zero(),
                'vacancy_fte' => BigDecimal::zero(),
                'overstaffing_fte' => BigDecimal::zero(),
                'capacity_hours' => BigDecimal::zero(),
            ];
            foreach (array_keys($totals[$month]) as $metric) {
                $totals[$month][$metric] = $totals[$month][$metric]->plus($row[$metric]);
            }
        }

        return array_map(
            static fn (array $metrics): array => array_map(
                static fn (BigDecimal $value): string => (string) $value->toScale(2, RoundingMode::HalfUp),
                $metrics,
            ),
            $totals,
        );
    }

    private function attendanceTotals(array $rows): array
    {
        $eligible = BigDecimal::zero();
        $present = BigDecimal::zero();
        $approvedAbsence = BigDecimal::zero();
        $absence = BigDecimal::zero();
        $overtime = BigDecimal::zero();
        foreach ($rows as $row) {
            $eligible = $eligible->plus($row['eligible_hours']);
            $present = $present->plus($row['present_hours']);
            $approvedAbsence = $approvedAbsence->plus($row['approved_absence_hours']);
            $absence = $absence->plus($row['unexplained_absence_hours']);
            $overtime = $overtime->plus($row['overtime_hours']);
        }
        $execution = $eligible->isZero()
            ? null
            : $present->plus($approvedAbsence)
                ->multipliedBy(100)
                ->dividedBy($eligible, 8, RoundingMode::HalfUp);
        if ($execution !== null && $execution->isGreaterThan(BigDecimal::of(100))) {
            $execution = BigDecimal::of(100);
        }

        return [
            'eligible_hours' => (string) $eligible->toScale(2, RoundingMode::HalfUp),
            'present_hours' => (string) $present->toScale(2, RoundingMode::HalfUp),
            'approved_absence_hours' => (string) $approvedAbsence->toScale(2, RoundingMode::HalfUp),
            'unexplained_absence_hours' => (string) $absence->toScale(2, RoundingMode::HalfUp),
            'overtime_hours' => (string) $overtime->toScale(2, RoundingMode::HalfUp),
            'execution_percent' => $execution === null ? null : (string) $execution->toScale(2, RoundingMode::HalfUp),
        ];
    }

    private function capacitySchema(): array
    {
        return array_map(
            static fn (string $id): array => ['id' => $id],
            [
                'month', 'department_name', 'position_name', 'project_name', 'employment_type',
                'rate_as_of', 'planned_fte',
                'assigned_fte', 'vacancy_fte', 'capacity_hours', 'rate_type', 'currency', 'rate',
            ],
        );
    }

    private function attendanceSchema(): array
    {
        return array_map(
            static fn (string $id): array => ['id' => $id],
            [
                'work_date', 'employee_name', 'project_name', 'site_name', 'shift',
                'eligible_hours', 'present_hours', 'overtime_hours', 'absence_hours',
                'execution_percent', 'status', 'close_version', 'audit_refs',
            ],
        );
    }

    private function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        if (! is_array($decoded)) {
            throw new DomainException('REPORT_SNAPSHOT_CORRUPT');
        }

        return $decoded;
    }
}
