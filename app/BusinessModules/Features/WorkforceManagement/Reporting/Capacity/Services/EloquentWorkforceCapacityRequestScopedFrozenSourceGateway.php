<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityRequestScopedFrozenSourceGateway;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCohortKey;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityFrozenCapturePins;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityFrozenCaptureRequestState;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityFrozenSourceProjection;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use LogicException;

final readonly class EloquentWorkforceCapacityRequestScopedFrozenSourceGateway implements WorkforceCapacityRequestScopedFrozenSourceGateway
{
    private const REQUESTS = 'workforce_capacity_capture_requests';

    private const RANGES = 'workforce_capacity_capture_ranges';

    private const SOURCE_ROWS = 'workforce_capacity_frozen_source_rows';

    public function __construct(private ConnectionInterface $database) {}

    public function isInsideOwnerTransaction(): bool
    {
        return $this->database->transactionLevel() > 0;
    }

    public function createRequest(WorkforceCapacityFrozenCapturePins $pins): WorkforceCapacityFrozenCaptureRequestState
    {
        $commandCanonical = $pins->commandCanonical();
        $policyCanonical = $pins->policyCanonical();
        $inserted = $this->database->table(self::REQUESTS)->insertOrIgnore([
            'organization_id' => $pins->command->organizationId,
            'mutation_id' => $pins->command->mutationId,
            'status' => 'preparing',
            'current_cursor' => null,
            'cohort_cursor' => null,
            'snapshot_count' => 0,
            'chunk_count' => 0,
            'attempt_count' => 0,
            'command_payload' => $commandCanonical,
            'command_canonical' => $commandCanonical,
            'command_hash' => $pins->commandHash(),
            'policy_definition' => $policyCanonical,
            'policy_canonical' => $policyCanonical,
            'policy_hash' => $pins->policyHash(),
            'source_schema_version' => $pins->sourceSchemaVersion,
            'formula_version' => $pins->formulaVersion,
            'business_date' => $pins->businessDate,
            'captured_at' => $pins->capturedAt,
            'range_count' => 0,
            'source_row_count' => 0,
            'available_at' => null,
            'claim_token' => null,
            'claimed_at' => null,
            'last_error_code' => null,
            'started_at' => $pins->capturedAt,
            'frozen_at' => null,
            'completed_at' => null,
            'dead_lettered_at' => null,
        ]);
        $request = $this->database->table(self::REQUESTS)
            ->where('organization_id', $pins->command->organizationId)
            ->where('mutation_id', $pins->command->mutationId)
            ->lockForUpdate()
            ->first();
        if (! is_object($request)
            || (string) $request->command_canonical !== $commandCanonical
            || ! hash_equals((string) $request->command_hash, $pins->commandHash())
            || (string) $request->policy_canonical !== $policyCanonical
            || ! hash_equals((string) $request->policy_hash, $pins->policyHash())
            || (string) $request->source_schema_version !== $pins->sourceSchemaVersion
            || (string) $request->formula_version !== $pins->formulaVersion
            || (string) $request->business_date !== $pins->businessDate
            || new DateTimeImmutable((string) $request->captured_at) != $pins->capturedAt) {
            throw new LogicException('workforce_capacity_deferred_idempotency_conflict');
        }

        $status = (string) $request->status;
        if (! in_array($status, ['preparing', 'pending', 'processing', 'completed', 'dead_lettered'], true)) {
            throw new LogicException('workforce_capacity_deferred_status_invalid');
        }
        if ($inserted === 0 && $status === 'preparing') {
            throw new LogicException('workforce_capacity_deferred_preparation_in_progress');
        }

        return new WorkforceCapacityFrozenCaptureRequestState(
            (int) $request->id,
            preparationRequired: $inserted === 1,
            dispatchRequired: in_array($status, ['pending', 'processing'], true),
        );
    }

    public function materializeRanges(WorkforceCapacityFrozenCapturePins $pins, int $captureRequestId): int
    {
        if ($captureRequestId < 1) {
            throw new InvalidArgumentException('workforce_capacity_capture_request_invalid');
        }
        if ($pins->command->sourceType === 'employee_lifecycle') {
            throw new LogicException('workforce_capacity_lifecycle_requires_staged_ranges');
        }

        return $this->database->affectingStatement(<<<'SQL'
WITH request AS (
    SELECT *
    FROM workforce_capacity_capture_requests
    WHERE id = ? AND status = 'preparing' AND frozen_at IS NULL
), states AS (
    SELECT request.*, state.value AS state
    FROM request
    CROSS JOIN LATERAL jsonb_array_elements(jsonb_build_array(
        request.command_payload->'old_state', request.command_payload->'new_state'
    )) AS state(value)
    WHERE jsonb_typeof(state.value) = 'object'
), impacted AS (
    SELECT request.id AS capture_request_id, request.organization_id,
           (state->>'staff_unit_id')::bigint AS staff_unit_id,
           NULLIF(state->>'project_id', '')::bigint AS project_id,
           COALESCE(NULLIF(state->>'valid_from', '')::date, request.business_date) AS valid_from,
           NULLIF(state->>'valid_to', '')::date AS valid_to,
           request.captured_at
      FROM request
      JOIN states ON true
     WHERE request.command_payload->>'source_type' = 'assignment'
    UNION ALL
    SELECT request.id, request.organization_id, (state->>'id')::bigint, NULL::bigint,
           COALESCE(NULLIF(state->>'valid_from', '')::date, request.business_date),
           NULLIF(state->>'valid_to', '')::date, request.captured_at
      FROM request
      JOIN states ON true
     WHERE request.command_payload->>'source_type' = 'staff_unit'
    UNION ALL
    SELECT DISTINCT request.id, request.organization_id, assignment.staff_unit_id, assignment.project_id,
           assignment.valid_from, assignment.valid_to, request.captured_at
      FROM request
      JOIN states ON true
      JOIN workforce_employee_assignments AS assignment
        ON assignment.organization_id = request.organization_id
       AND assignment.status = 'active'
       AND assignment.deleted_at IS NULL
       AND (
            (request.command_payload->>'source_type' = 'staff_unit'
             AND assignment.staff_unit_id = (state->>'id')::bigint)
         OR (request.command_payload->>'source_type' = 'schedule'
             AND assignment.work_schedule_id = (state->>'id')::bigint)
       )
     WHERE request.command_payload->>'source_type' IN ('staff_unit', 'schedule')
    UNION ALL
    SELECT DISTINCT request.id, request.organization_id, assignment.staff_unit_id, assignment.project_id,
           (state->>'work_date')::date, (state->>'work_date')::date, request.captured_at
      FROM request
      JOIN states ON true
      JOIN workforce_employee_assignments AS assignment
        ON assignment.organization_id = request.organization_id
       AND assignment.status = 'active'
       AND assignment.deleted_at IS NULL
       AND assignment.work_schedule_id = (state->>'work_schedule_id')::bigint
       AND assignment.valid_from <= (state->>'work_date')::date
       AND (assignment.valid_to IS NULL OR assignment.valid_to >= (state->>'work_date')::date)
     WHERE request.command_payload->>'source_type' = 'schedule_day'
    UNION ALL
    SELECT DISTINCT request.id, request.organization_id, assignment.staff_unit_id, assignment.project_id,
           GREATEST((state->>'start_date')::date, assignment.valid_from),
           LEAST((state->>'end_date')::date, COALESCE(assignment.valid_to, (state->>'end_date')::date)),
           request.captured_at
      FROM request
      JOIN states ON true
      JOIN workforce_employee_assignments AS assignment
        ON assignment.organization_id = request.organization_id
       AND assignment.status = 'active'
       AND assignment.deleted_at IS NULL
       AND assignment.employee_id = (state->>'employee_id')::bigint
       AND assignment.valid_from <= (state->>'end_date')::date
       AND (assignment.valid_to IS NULL OR assignment.valid_to >= (state->>'start_date')::date)
     WHERE request.command_payload->>'source_type' IN ('absence', 'business_trip')
       AND state->>'status' = 'approved'
    UNION ALL
    SELECT request.id, request.organization_id, (state->>'staff_unit_id')::bigint,
           NULLIF(state->>'project_id', '')::bigint,
           (state->>'month_start')::date, (state->>'month_start')::date, request.captured_at
      FROM request
      JOIN states ON true
     WHERE request.command_payload->>'source_type' = 'capture_request'
), normalized AS (
    SELECT impacted.*,
           GREATEST(impacted.valid_from, request.business_date) AS effective_from,
           CASE
               WHEN impacted.valid_to IS NULL THEN GREATEST(impacted.valid_from, request.business_date)
               ELSE GREATEST(impacted.valid_to, request.business_date)
           END AS effective_through
      FROM impacted
      JOIN request ON request.id = impacted.capture_request_id
     WHERE impacted.staff_unit_id > 0
       AND (impacted.valid_to IS NULL OR impacted.valid_to >= impacted.valid_from)
)
INSERT INTO workforce_capacity_capture_ranges (
    capture_request_id, organization_id, staff_unit_id, project_id,
    from_month, through_month, created_at
)
SELECT DISTINCT normalized.capture_request_id, normalized.organization_id, normalized.staff_unit_id,
       bucket.project_id, date_trunc('month', normalized.effective_from)::date,
       date_trunc('month', normalized.effective_through)::date, normalized.captured_at
  FROM normalized
  CROSS JOIN LATERAL (
      SELECT NULL::bigint AS project_id
      UNION
      SELECT normalized.project_id WHERE normalized.project_id IS NOT NULL
  ) AS bucket
ON CONFLICT DO NOTHING
SQL, [$captureRequestId]);
    }

    public function stageLifecycleRanges(
        int $captureRequestId,
        int $organizationId,
        int $employeeId,
        string $dismissalDate,
    ): int {
        if ($captureRequestId < 1 || $organizationId < 1 || $employeeId < 1) {
            throw new InvalidArgumentException('workforce_capacity_lifecycle_identity_invalid');
        }

        return $this->database->affectingStatement(<<<'SQL'
INSERT INTO workforce_capacity_capture_ranges (
    capture_request_id, organization_id, staff_unit_id, project_id,
    from_month, through_month, created_at
)
SELECT DISTINCT
    request.id,
    assignment.organization_id,
    assignment.staff_unit_id,
    bucket.project_id,
    date_trunc('month', GREATEST(assignment.valid_from, request.business_date))::date,
    date_trunc('month', CASE
        WHEN assignment.valid_to IS NULL THEN GREATEST(assignment.valid_from, request.business_date)
        ELSE GREATEST(assignment.valid_to, request.business_date)
    END)::date,
    request.captured_at
FROM workforce_capacity_capture_requests AS request
JOIN workforce_employee_assignments AS assignment
  ON assignment.organization_id = request.organization_id
 AND assignment.employee_id = ?
CROSS JOIN LATERAL (
    SELECT NULL::bigint AS project_id
    UNION
    SELECT assignment.project_id WHERE assignment.project_id IS NOT NULL
) AS bucket
WHERE request.id = ?
  AND request.organization_id = ?
  AND request.status = 'preparing'
  AND request.frozen_at IS NULL
  AND assignment.status = 'active'
  AND assignment.deleted_at IS NULL
ON CONFLICT DO NOTHING
SQL, [$employeeId, $captureRequestId, $organizationId]);
    }

    public function materializeSourceRows(int $captureRequestId): int
    {
        if ($captureRequestId < 1) {
            throw new InvalidArgumentException('workforce_capacity_capture_request_invalid');
        }

        $total = 0;
        foreach ($this->sourceCandidateQueries() as $query) {
            $total += $this->insertSourceCandidates($captureRequestId, $query);
        }

        return $total;
    }

    public function sealRequest(int $captureRequestId, int $rangeCount, int $sourceRowCount): bool
    {
        if ($captureRequestId < 1 || $rangeCount < 0 || $sourceRowCount < 0) {
            throw new InvalidArgumentException('workforce_capacity_frozen_seal_invalid');
        }
        $sealedAt = $this->now();
        $query = $this->database->table(self::REQUESTS)
            ->where('id', $captureRequestId)
            ->where('status', 'preparing')
            ->whereNull('frozen_at');
        if ($rangeCount > 0) {
            $query->whereExists(function ($query) use ($captureRequestId): void {
                $query->selectRaw('1')
                    ->from(self::RANGES)
                    ->where('capture_request_id', $captureRequestId);
            });
        }
        $updated = $query->update([
            'status' => $rangeCount === 0 ? 'completed' : 'pending',
            'range_count' => $rangeCount,
            'source_row_count' => $sourceRowCount,
            'available_at' => $rangeCount === 0 ? null : $sealedAt,
            'frozen_at' => $sealedAt,
            'completed_at' => $rangeCount === 0 ? $sealedAt : null,
        ]);
        if ($updated !== 1) {
            throw new LogicException('workforce_capacity_frozen_seal_failed');
        }

        return $rangeCount > 0;
    }

    public function nextKeys(int $captureRequestId, ?string $afterSortIdentity, int $limit): array
    {
        if ($captureRequestId < 1 || $limit < 1 || $limit > 65) {
            throw new InvalidArgumentException('workforce_capacity_deferred_key_limit_invalid');
        }
        $rows = $this->database->select(<<<'SQL'
WITH expanded AS (
    SELECT DISTINCT
        request.organization_id,
        request.business_date,
        generated.month_start::date AS month_start,
        range.staff_unit_id,
        range.project_id,
        lpad(request.organization_id::text, 20, '0') || ':'
          || generated.month_start::date::text || ':'
          || lpad(range.staff_unit_id::text, 20, '0') || ':'
          || CASE WHEN range.project_id IS NULL THEN '0' ELSE '1' END || ':'
          || lpad(COALESCE(range.project_id, 0)::text, 20, '0') AS sort_identity
    FROM workforce_capacity_capture_requests AS request
    JOIN workforce_capacity_capture_ranges AS range ON range.capture_request_id = request.id
    CROSS JOIN LATERAL generate_series(
        range.from_month::timestamp,
        range.through_month::timestamp,
        interval '1 month'
    ) AS generated(month_start)
    WHERE request.id = ? AND request.frozen_at IS NOT NULL
)
SELECT
    organization_id,
    CASE
        WHEN date_trunc('month', business_date)::date = month_start THEN business_date
        ELSE (month_start + interval '1 month - 1 day')::date
    END AS as_of_date,
    month_start,
    staff_unit_id,
    project_id,
    sort_identity
FROM expanded
WHERE (? IS NULL OR sort_identity > ?)
ORDER BY sort_identity
LIMIT ?
SQL, [$captureRequestId, $afterSortIdentity, $afterSortIdentity, $limit]);

        return array_map(static fn (object $row): WorkforceCapacityCohortKey => new WorkforceCapacityCohortKey(
            (int) $row->organization_id,
            (string) $row->as_of_date,
            (string) $row->month_start,
            (int) $row->staff_unit_id,
            $row->project_id === null ? null : (int) $row->project_id,
        ), $rows);
    }

    public function sourceProjections(int $captureRequestId, array $keys): iterable
    {
        if ($captureRequestId < 1 || $keys === [] || count($keys) > 64) {
            throw new InvalidArgumentException('workforce_capacity_deferred_source_batch_invalid');
        }
        $values = [];
        $bindings = [];
        foreach ($keys as $position => $key) {
            if (! $key instanceof WorkforceCapacityCohortKey) {
                throw new InvalidArgumentException('workforce_capacity_deferred_source_batch_invalid');
            }
            $values[] = '(?, ?, ?, ?::date, ?::date, ?::date, ?, ?)';
            array_push(
                $bindings,
                $position,
                $key->identity(),
                $key->organizationId,
                $key->asOfDate,
                $key->monthStart,
                (new DateTimeImmutable($key->monthStart))->modify('last day of this month')->format('Y-m-d'),
                $key->staffUnitId,
                $key->projectId,
            );
        }
        $bindings[] = $captureRequestId;
        $sql = str_replace('__VALUES__', implode(', ', $values), <<<'SQL'
WITH requested (
    position, cohort_identity, organization_id, as_of_date, month_start, month_end, staff_unit_id, project_id
) AS (VALUES __VALUES__),
assignments AS (
    SELECT requested.*, source_row.id AS frozen_row_id, source_row.employee_id, source_row.schedule_id,
           source_row.payload
    FROM requested
    JOIN workforce_capacity_frozen_source_rows AS source_row
      ON source_row.capture_request_id = ?
     AND source_row.organization_id = requested.organization_id
     AND source_row.source_type = 'assignment'
     AND source_row.staff_unit_id = requested.staff_unit_id
     AND source_row.project_id IS NOT DISTINCT FROM requested.project_id
     AND source_row.valid_from <= requested.as_of_date
     AND (source_row.valid_to IS NULL OR source_row.valid_to >= requested.as_of_date)
     AND source_row.payload->>'status' = 'active'
     AND source_row.payload->'deleted_at' = 'null'::jsonb
),
evidence AS (
    SELECT DISTINCT requested.position, requested.cohort_identity, 1 AS type_order,
           source_row.id AS frozen_row_id, source_row.source_type, source_row.payload
      FROM requested
      JOIN workforce_capacity_frozen_source_rows AS source_row
        ON source_row.capture_request_id = ?
       AND source_row.organization_id = requested.organization_id
       AND source_row.source_type = 'staff_unit'
       AND source_row.source_id = requested.staff_unit_id
    UNION ALL
    SELECT position, cohort_identity, 2, frozen_row_id, 'assignment', payload FROM assignments
    UNION
    SELECT DISTINCT assignment.position, assignment.cohort_identity, 3, source_row.id,
           source_row.source_type, source_row.payload
      FROM assignments AS assignment
      JOIN workforce_capacity_frozen_source_rows AS source_row
        ON source_row.capture_request_id = ?
       AND source_row.source_type = 'employee_lifecycle'
       AND source_row.employee_id = assignment.employee_id
    UNION
    SELECT DISTINCT assignment.position, assignment.cohort_identity, 4, source_row.id,
           source_row.source_type, source_row.payload
      FROM assignments AS assignment
      JOIN workforce_capacity_frozen_source_rows AS source_row
        ON source_row.capture_request_id = ?
       AND source_row.source_type = 'schedule'
       AND source_row.source_id = assignment.schedule_id
    UNION
    SELECT DISTINCT assignment.position, assignment.cohort_identity, 5, source_row.id,
           source_row.source_type, source_row.payload
      FROM assignments AS assignment
      JOIN workforce_capacity_frozen_source_rows AS source_row
        ON source_row.capture_request_id = ?
       AND source_row.source_type = 'schedule_day'
       AND source_row.schedule_id = assignment.schedule_id
       AND source_row.work_date BETWEEN assignment.month_start AND assignment.month_end
    UNION
    SELECT DISTINCT assignment.position, assignment.cohort_identity, 6, source_row.id,
           source_row.source_type, source_row.payload
      FROM assignments AS assignment
      JOIN workforce_capacity_frozen_source_rows AS source_row
        ON source_row.capture_request_id = ?
       AND source_row.source_type = 'absence'
       AND source_row.employee_id = assignment.employee_id
       AND source_row.valid_from <= assignment.as_of_date
       AND source_row.valid_to >= assignment.as_of_date
    UNION
    SELECT DISTINCT assignment.position, assignment.cohort_identity, 7, source_row.id,
           source_row.source_type, source_row.payload
      FROM assignments AS assignment
      JOIN workforce_capacity_frozen_source_rows AS source_row
        ON source_row.capture_request_id = ?
       AND source_row.source_type = 'business_trip'
       AND source_row.employee_id = assignment.employee_id
       AND source_row.valid_from <= assignment.as_of_date
       AND source_row.valid_to >= assignment.as_of_date
)
SELECT cohort_identity, source_type, payload
FROM evidence
ORDER BY position, type_order, frozen_row_id
SQL);
        array_push(
            $bindings,
            $captureRequestId,
            $captureRequestId,
            $captureRequestId,
            $captureRequestId,
            $captureRequestId,
            $captureRequestId,
        );

        foreach ($this->database->cursor($sql, $bindings) as $row) {
            $payload = is_string($row->payload)
                ? json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR)
                : (array) $row->payload;
            yield new WorkforceCapacityFrozenSourceProjection(
                (string) $row->cohort_identity,
                (string) $row->source_type,
                $payload,
            );
        }
    }

    private function insertSourceCandidates(int $captureRequestId, string $candidateQuery): int
    {
        return $this->database->affectingStatement(str_replace('__CANDIDATES__', $candidateQuery, <<<'SQL'
WITH candidates AS (__CANDIDATES__), canonical AS (
    SELECT candidates.*, payload::text AS payload_canonical
    FROM candidates
)
INSERT INTO workforce_capacity_frozen_source_rows (
    capture_request_id, organization_id, source_type, source_id, source_key,
    staff_unit_id, project_id, employee_id, schedule_id,
    valid_from, valid_to, work_date,
    payload, payload_canonical, payload_hash, created_at
)
SELECT
    capture_request_id, organization_id, source_type, source_id, source_key,
    staff_unit_id, project_id, employee_id, schedule_id,
    valid_from, valid_to, work_date,
    payload, payload_canonical,
    encode(sha256(convert_to(payload_canonical, 'UTF8')), 'hex'),
    captured_at
FROM canonical
ON CONFLICT DO NOTHING
SQL), [$captureRequestId]);
    }

    private function sourceCandidateQueries(): array
    {
        return [
            <<<'SQL'
SELECT DISTINCT request.id AS capture_request_id, unit.organization_id, 'staff_unit'::text AS source_type,
       unit.id AS source_id, 'staff_unit:' || unit.id AS source_key,
       unit.id AS staff_unit_id, NULL::bigint AS project_id, NULL::bigint AS employee_id,
       NULL::bigint AS schedule_id, unit.valid_from, unit.valid_to, NULL::date AS work_date,
       request.captured_at,
       jsonb_build_object(
           'id', unit.id, 'organization_id', unit.organization_id, 'department_id', unit.department_id,
           'position_id', unit.position_id, 'headcount', unit.headcount::text, 'rate', unit.rate::text,
           'valid_from', unit.valid_from::text, 'valid_to', unit.valid_to::text, 'is_active', unit.is_active,
           'deleted_at', unit.deleted_at::text
       ) AS payload
FROM workforce_capacity_capture_requests AS request
JOIN workforce_capacity_capture_ranges AS range ON range.capture_request_id = request.id
JOIN workforce_staff_units AS unit
  ON unit.organization_id = range.organization_id AND unit.id = range.staff_unit_id
WHERE request.id = ? AND request.status = 'preparing' AND request.frozen_at IS NULL
SQL,
            <<<'SQL'
SELECT DISTINCT request.id AS capture_request_id, assignment.organization_id, 'assignment'::text AS source_type,
       assignment.id AS source_id, 'assignment:' || assignment.id AS source_key,
       assignment.staff_unit_id, assignment.project_id, assignment.employee_id,
       assignment.work_schedule_id AS schedule_id, assignment.valid_from, assignment.valid_to,
       NULL::date AS work_date, request.captured_at,
       jsonb_build_object(
           'id', assignment.id, 'organization_id', assignment.organization_id,
           'employee_id', assignment.employee_id, 'staff_unit_id', assignment.staff_unit_id,
           'department_id', assignment.department_id, 'position_id', assignment.position_id,
           'project_id', assignment.project_id, 'work_schedule_id', assignment.work_schedule_id,
           'rate', assignment.rate::text, 'valid_from', assignment.valid_from::text,
           'valid_to', assignment.valid_to::text, 'status', assignment.status,
           'deleted_at', assignment.deleted_at::text
       ) AS payload
FROM workforce_capacity_capture_requests AS request
JOIN workforce_capacity_capture_ranges AS range ON range.capture_request_id = request.id
JOIN workforce_employee_assignments AS assignment
  ON assignment.organization_id = range.organization_id
 AND assignment.staff_unit_id = range.staff_unit_id
 AND assignment.project_id IS NOT DISTINCT FROM range.project_id
 AND assignment.valid_from <= (range.through_month + interval '1 month - 1 day')::date
 AND (assignment.valid_to IS NULL OR assignment.valid_to >= range.from_month)
WHERE request.id = ? AND request.status = 'preparing' AND request.frozen_at IS NULL
  AND assignment.status = 'active' AND assignment.deleted_at IS NULL
SQL,
            <<<'SQL'
SELECT DISTINCT request.id AS capture_request_id, schedule.organization_id, 'schedule'::text AS source_type,
       schedule.id AS source_id, 'schedule:' || schedule.id AS source_key,
       NULL::bigint AS staff_unit_id, NULL::bigint AS project_id, NULL::bigint AS employee_id,
       schedule.id AS schedule_id, NULL::date AS valid_from, NULL::date AS valid_to,
       NULL::date AS work_date, request.captured_at,
       jsonb_build_object(
           'id', schedule.id, 'organization_id', schedule.organization_id,
           'schedule_type', schedule.schedule_type, 'hours_per_day', schedule.hours_per_day::text,
           'week_pattern', schedule.week_pattern::text, 'is_active', schedule.is_active,
           'deleted_at', schedule.deleted_at::text
       ) AS payload
FROM workforce_capacity_capture_requests AS request
JOIN workforce_capacity_frozen_source_rows AS assignment
  ON assignment.capture_request_id = request.id
 AND assignment.source_type = 'assignment'
JOIN workforce_work_schedules AS schedule
  ON schedule.organization_id = request.organization_id AND schedule.id = assignment.schedule_id
WHERE request.id = ? AND request.status = 'preparing' AND request.frozen_at IS NULL
SQL,
            <<<'SQL'
SELECT DISTINCT request.id AS capture_request_id, day.organization_id, 'schedule_day'::text AS source_type,
       day.id AS source_id, 'schedule_day:' || day.id AS source_key,
       NULL::bigint AS staff_unit_id, NULL::bigint AS project_id, NULL::bigint AS employee_id,
       day.work_schedule_id AS schedule_id, NULL::date AS valid_from, NULL::date AS valid_to,
       day.work_date, request.captured_at,
       jsonb_build_object(
           'id', day.id, 'organization_id', day.organization_id,
           'work_schedule_id', day.work_schedule_id, 'work_date', day.work_date::text,
           'day_type', day.day_type, 'planned_hours', day.planned_hours::text
       ) AS payload
FROM workforce_capacity_capture_requests AS request
JOIN workforce_capacity_frozen_source_rows AS assignment
  ON assignment.capture_request_id = request.id
 AND assignment.source_type = 'assignment'
JOIN workforce_capacity_capture_ranges AS range
  ON range.capture_request_id = request.id
 AND range.staff_unit_id = assignment.staff_unit_id
 AND range.project_id IS NOT DISTINCT FROM assignment.project_id
JOIN workforce_work_schedule_days AS day
  ON day.organization_id = request.organization_id
 AND day.work_schedule_id = assignment.schedule_id
 AND day.work_date BETWEEN range.from_month AND (range.through_month + interval '1 month - 1 day')::date
WHERE request.id = ? AND request.status = 'preparing' AND request.frozen_at IS NULL
SQL,
            <<<'SQL'
SELECT DISTINCT request.id AS capture_request_id, absence.organization_id, 'absence'::text AS source_type,
       absence.id AS source_id, 'absence:' || absence.id AS source_key,
       NULL::bigint AS staff_unit_id, NULL::bigint AS project_id, absence.employee_id,
       NULL::bigint AS schedule_id, absence.start_date AS valid_from, absence.end_date AS valid_to,
       NULL::date AS work_date, request.captured_at,
       jsonb_build_object(
           'id', absence.id, 'organization_id', absence.organization_id,
           'employee_id', absence.employee_id, 'absence_type_id', absence.absence_type_id,
           'start_date', absence.start_date::text, 'end_date', absence.end_date::text,
           'status', absence.status, 'deleted_at', absence.deleted_at::text,
           'affects_payroll', type.affects_payroll
       ) AS payload
FROM workforce_capacity_capture_requests AS request
JOIN workforce_capacity_frozen_source_rows AS assignment
  ON assignment.capture_request_id = request.id
 AND assignment.source_type = 'assignment'
JOIN workforce_absences AS absence
  ON absence.organization_id = request.organization_id
 AND absence.employee_id = assignment.employee_id
 AND absence.start_date <= COALESCE(assignment.valid_to, 'infinity'::date)
 AND absence.end_date >= assignment.valid_from
JOIN workforce_absence_types AS type
  ON type.organization_id = absence.organization_id AND type.id = absence.absence_type_id
JOIN workforce_capacity_capture_ranges AS range
  ON range.capture_request_id = request.id
 AND range.staff_unit_id = assignment.staff_unit_id
 AND range.project_id IS NOT DISTINCT FROM assignment.project_id
 AND absence.start_date <= (range.through_month + interval '1 month - 1 day')::date
 AND absence.end_date >= range.from_month
WHERE request.id = ? AND request.status = 'preparing' AND request.frozen_at IS NULL
  AND absence.status = 'approved' AND absence.deleted_at IS NULL AND type.affects_payroll = true
SQL,
            <<<'SQL'
SELECT DISTINCT request.id AS capture_request_id, trip.organization_id, 'business_trip'::text AS source_type,
       trip.id AS source_id, 'business_trip:' || trip.id AS source_key,
       NULL::bigint AS staff_unit_id, trip.project_id, trip.employee_id,
       NULL::bigint AS schedule_id, trip.start_date AS valid_from, trip.end_date AS valid_to,
       NULL::date AS work_date, request.captured_at,
       jsonb_build_object(
           'id', trip.id, 'organization_id', trip.organization_id, 'employee_id', trip.employee_id,
           'project_id', trip.project_id, 'start_date', trip.start_date::text,
           'end_date', trip.end_date::text, 'status', trip.status,
           'deleted_at', trip.deleted_at::text
       ) AS payload
FROM workforce_capacity_capture_requests AS request
JOIN workforce_capacity_frozen_source_rows AS assignment
  ON assignment.capture_request_id = request.id
 AND assignment.source_type = 'assignment'
JOIN workforce_business_trips AS trip
  ON trip.organization_id = request.organization_id
 AND trip.employee_id = assignment.employee_id
 AND trip.start_date <= COALESCE(assignment.valid_to, 'infinity'::date)
 AND trip.end_date >= assignment.valid_from
JOIN workforce_capacity_capture_ranges AS range
  ON range.capture_request_id = request.id
 AND range.staff_unit_id = assignment.staff_unit_id
 AND range.project_id IS NOT DISTINCT FROM assignment.project_id
 AND trip.start_date <= (range.through_month + interval '1 month - 1 day')::date
 AND trip.end_date >= range.from_month
WHERE request.id = ? AND request.status = 'preparing' AND request.frozen_at IS NULL
  AND trip.status = 'approved' AND trip.deleted_at IS NULL
SQL,
            <<<'SQL'
SELECT DISTINCT request.id AS capture_request_id, employee.organization_id,
       'employee_lifecycle'::text AS source_type, employee.id AS source_id,
       'employee_lifecycle:' || employee.id AS source_key,
       NULL::bigint AS staff_unit_id, NULL::bigint AS project_id, employee.id AS employee_id,
       NULL::bigint AS schedule_id, NULL::date AS valid_from, employee.dismissal_date AS valid_to,
       NULL::date AS work_date, request.captured_at,
       jsonb_build_object(
           'id', employee.id, 'organization_id', employee.organization_id,
           'employee_id', employee.id, 'employment_status', employee.employment_status,
           'dismissal_date', employee.dismissal_date::text
       ) AS payload
FROM workforce_capacity_capture_requests AS request
JOIN workforce_capacity_frozen_source_rows AS assignment
  ON assignment.capture_request_id = request.id
 AND assignment.source_type = 'assignment'
JOIN workforce_employees AS employee
  ON employee.organization_id = request.organization_id AND employee.id = assignment.employee_id
WHERE request.id = ? AND request.status = 'preparing' AND request.frozen_at IS NULL
SQL,
        ];
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
