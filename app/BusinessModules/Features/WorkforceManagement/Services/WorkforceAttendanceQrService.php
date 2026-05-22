<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Services;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\BusinessModules\Features\WorkforceManagement\Exceptions\WorkforceAttendanceException;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class WorkforceAttendanceQrService
{
    private const TOKEN_TTL_MINUTES = 5;

    public function issue(int $organizationId, User $user, array $payload): array
    {
        $workDate = CarbonImmutable::parse((string) $payload['work_date'])->toDateString();
        $projectId = isset($payload['project_id']) ? (int) $payload['project_id'] : null;
        $employee = $this->linkedEmployee($organizationId, (int) $user->id, $workDate);

        if ($projectId !== null) {
            $this->assertProject($organizationId, $projectId);
        }

        $token = Str::random(80);
        $expiresAt = now()->addMinutes(self::TOKEN_TTL_MINUTES);
        $payloadHash = $this->payloadHash($organizationId, (int) $employee->id, $projectId, $workDate);

        DB::table('workforce_attendance_qr_tokens')->insert([
            'organization_id' => $organizationId,
            'employee_id' => $employee->id,
            'project_id' => $projectId,
            'work_date' => $workDate,
            'token_hash' => $this->tokenHash($token),
            'payload_hash' => $payloadHash,
            'expires_at' => $expiresAt,
            'status' => 'active',
            'created_by_user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'qr_token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
            'employee_id' => (int) $employee->id,
            'employee_label' => $employee->full_name,
            'project_id' => $projectId,
            'project_label' => $projectId !== null ? $this->projectLabel($organizationId, $projectId) : null,
            'work_date' => $workDate,
            'status' => 'active',
            'status_label' => trans_message('workforce.attendance.qr_status_ready'),
        ];
    }

    public function scan(int $organizationId, User $scanner, array $payload): array
    {
        $tokenValue = trim((string) $payload['qr_token']);
        $deviceId = isset($payload['device_id']) ? trim((string) $payload['device_id']) : null;

        $result = DB::transaction(function () use ($organizationId, $scanner, $tokenValue, $deviceId): array {
            $token = DB::table('workforce_attendance_qr_tokens')
                ->where('organization_id', $organizationId)
                ->where('token_hash', $this->tokenHash($tokenValue))
                ->lockForUpdate()
                ->first();

            if ($token === null) {
                return $this->rejected(null, trans_message('workforce.errors.qr_token_invalid'), 'invalid_scan');
            }

            if ((string) $token->status === 'used' || $token->used_at !== null) {
                return $this->rejected(
                    $token,
                    trans_message('workforce.errors.qr_token_already_used'),
                    'duplicate_scan',
                    409
                );
            }

            if ((string) $token->status !== 'active') {
                return $this->rejected($token, trans_message('workforce.errors.qr_token_invalid'), 'invalid_scan');
            }

            if (CarbonImmutable::parse((string) $token->expires_at)->isPast()) {
                DB::table('workforce_attendance_qr_tokens')
                    ->where('id', $token->id)
                    ->update(['status' => 'expired', 'updated_at' => now()]);
                return $this->rejected($token, trans_message('workforce.errors.qr_token_expired'), 'expired_scan');
            }

            $employee = $this->employee($organizationId, (int) $token->employee_id);
            $workDate = (string) $token->work_date;

            if (! $this->employeeActiveOnDate($employee, $workDate)) {
                return $this->rejected($token, trans_message('workforce.errors.qr_employee_inactive'), 'inactive_employee');
            }

            if ($token->project_id !== null) {
                $this->assertProject($organizationId, (int) $token->project_id);
            }

            DB::table('workforce_attendance_qr_tokens')
                ->where('id', $token->id)
                ->update([
                    'status' => 'used',
                    'used_at' => now(),
                    'used_by_user_id' => $scanner->id,
                    'updated_at' => now(),
                ]);

            $confirmedAt = now();
            $scanId = $this->logScan(
                organizationId: $organizationId,
                token: $token,
                scannerId: (int) $scanner->id,
                result: 'confirmed',
                failureReason: null,
                deviceId: $deviceId,
                source: 'qr_scan',
                scannedAt: $confirmedAt
            );

            return [
                'scan_event_id' => $scanId,
                'employee_id' => (int) $employee->id,
                'employee_label' => $employee->full_name,
                'project_id' => $token->project_id !== null ? (int) $token->project_id : null,
                'project_label' => $token->project_id !== null ? $this->projectLabel($organizationId, (int) $token->project_id) : null,
                'work_date' => $workDate,
                'status' => 'at_work',
                'status_label' => trans_message('workforce.attendance.qr_status_confirmed'),
                'source' => 'qr_scan',
                'source_label' => trans_message('workforce.attendance.qr_source_label'),
                'confirmed_at' => $confirmedAt->toIso8601String(),
            ];
        });

        if (($result['rejected'] ?? false) === true) {
            $this->logScan(
                organizationId: $organizationId,
                token: $result['token'],
                scannerId: (int) $scanner->id,
                result: 'rejected',
                failureReason: (string) $result['message'],
                deviceId: $deviceId,
                source: 'qr_scan'
            );

            throw new WorkforceAttendanceException(
                (string) $result['message'],
                (string) $result['code'],
                (int) $result['status_code']
            );
        }

        return $result;
    }

    public function selfAttendance(int $organizationId, User $user, array $payload): array
    {
        $workDate = CarbonImmutable::parse((string) $payload['work_date'])->toDateString();
        $projectId = isset($payload['project_id']) ? (int) $payload['project_id'] : null;
        $deviceId = isset($payload['device_id']) ? trim((string) $payload['device_id']) : null;
        $employee = $this->linkedEmployee($organizationId, (int) $user->id, $workDate);

        if ($projectId !== null) {
            $this->assertProject($organizationId, $projectId);
        }

        if ($this->confirmedAttendanceExists($organizationId, (int) $employee->id, $workDate, $projectId)) {
            throw new WorkforceAttendanceException(
                trans_message('workforce.errors.attendance_already_confirmed'),
                'duplicate_attendance',
                409
            );
        }

        $confirmedAt = now();
        $token = (object) [
            'id' => null,
            'employee_id' => (int) $employee->id,
            'project_id' => $projectId,
            'work_date' => $workDate,
        ];
        $scanId = $this->logScan(
            organizationId: $organizationId,
            token: $token,
            scannerId: (int) $user->id,
            result: 'confirmed',
            failureReason: null,
            deviceId: $deviceId,
            source: 'self_attendance',
            scannedAt: $confirmedAt
        );

        return [
            'scan_event_id' => $scanId,
            'employee_id' => (int) $employee->id,
            'employee_label' => $employee->full_name,
            'project_id' => $projectId,
            'project_label' => $projectId !== null ? $this->projectLabel($organizationId, $projectId) : null,
            'work_date' => $workDate,
            'status' => 'at_work',
            'status_label' => trans_message('workforce.attendance.qr_status_confirmed'),
            'source' => 'self_attendance',
            'source_label' => trans_message('workforce.attendance.self_source_label'),
            'confirmed_at' => $confirmedAt->toIso8601String(),
        ];
    }

    public function history(int $organizationId, User $user, array $payload): array
    {
        $dateFrom = CarbonImmutable::parse((string) $payload['date_from'])->toDateString();
        $dateTo = CarbonImmutable::parse((string) $payload['date_to'])->toDateString();
        $projectId = isset($payload['project_id']) ? (int) $payload['project_id'] : null;
        $employee = $this->linkedEmployee($organizationId, (int) $user->id, $dateTo);

        if ($projectId !== null) {
            $this->assertProject($organizationId, $projectId);
        }

        $items = DB::table('workforce_attendance_scan_events as scan')
            ->leftJoin('projects as project', 'project.id', '=', 'scan.project_id')
            ->where('scan.organization_id', $organizationId)
            ->where('scan.employee_id', $employee->id)
            ->where('scan.result', 'confirmed')
            ->whereBetween('scan.work_date', [$dateFrom, $dateTo])
            ->when($projectId !== null, fn ($query) => $query->where('scan.project_id', $projectId))
            ->orderByDesc('scan.work_date')
            ->orderByDesc('scan.scanned_at')
            ->select([
                'scan.id',
                'scan.employee_id',
                'scan.project_id',
                'project.name as project_label',
                'scan.work_date',
                'scan.scanned_at',
                'scan.metadata',
            ])
            ->get()
            ->map(fn (object $scan): array => $this->historyPayload($scan, $employee))
            ->all();

        return ['items' => $items];
    }

    public function audit(int $organizationId): array
    {
        return DB::table('workforce_attendance_scan_events as scan')
            ->leftJoin('workforce_employees as employee', 'employee.id', '=', 'scan.employee_id')
            ->leftJoin('projects as project', 'project.id', '=', 'scan.project_id')
            ->leftJoin('users as scanner', 'scanner.id', '=', 'scan.scanned_by_user_id')
            ->where('scan.organization_id', $organizationId)
            ->orderByDesc('scan.scanned_at')
            ->limit(100)
            ->select([
                'scan.id',
                'scan.employee_id',
                'employee.last_name',
                'employee.first_name',
                'employee.middle_name',
                'scan.project_id',
                'project.name as project_label',
                'scanner.name as scanner_label',
                'scan.work_date',
                'scan.result',
                'scan.result_label',
                'scan.failure_reason',
                'scan.device_id',
                'scan.scanned_at',
            ])
            ->get()
            ->map(fn (object $scan): array => [
                'id' => (int) $scan->id,
                'employee_id' => $scan->employee_id !== null ? (int) $scan->employee_id : null,
                'employee_label' => trim(implode(' ', array_filter([$scan->last_name, $scan->first_name, $scan->middle_name]))) ?: null,
                'project_id' => $scan->project_id !== null ? (int) $scan->project_id : null,
                'project_label' => $scan->project_label,
                'scanner_label' => $scan->scanner_label,
                'work_date' => $scan->work_date,
                'result' => $scan->result,
                'result_label' => $scan->result_label,
                'failure_reason' => $scan->failure_reason,
                'device_id' => $scan->device_id,
                'scanned_at' => $scan->scanned_at,
            ])
            ->all();
    }

    private function linkedEmployee(int $organizationId, int $userId, string $workDate): WorkforceEmployee
    {
        $employee = WorkforceEmployee::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->first();

        if ($employee === null) {
            throw new DomainException(trans_message('workforce.errors.qr_employee_not_linked'));
        }

        if (! $this->employeeActiveOnDate($employee, $workDate)) {
            throw new DomainException(trans_message('workforce.errors.qr_employee_inactive'));
        }

        return $employee;
    }

    private function employee(int $organizationId, int $employeeId): WorkforceEmployee
    {
        $employee = WorkforceEmployee::query()
            ->where('organization_id', $organizationId)
            ->whereKey($employeeId)
            ->whereNull('deleted_at')
            ->first();

        if ($employee === null) {
            throw new DomainException(trans_message('workforce.errors.employee_not_found'));
        }

        return $employee;
    }

    private function employeeActiveOnDate(WorkforceEmployee $employee, string $workDate): bool
    {
        if ($employee->employment_status !== 'active') {
            return false;
        }

        if ($employee->hire_date !== null && $employee->hire_date->gt(CarbonImmutable::parse($workDate))) {
            return false;
        }

        return $employee->dismissal_date === null || ! $employee->dismissal_date->lt(CarbonImmutable::parse($workDate));
    }

    private function assertProject(int $organizationId, int $projectId): void
    {
        if (! Project::query()->where('organization_id', $organizationId)->whereKey($projectId)->exists()) {
            throw new DomainException(trans_message('workforce.errors.qr_project_unavailable'));
        }
    }

    private function projectLabel(int $organizationId, int $projectId): ?string
    {
        return Project::query()
            ->where('organization_id', $organizationId)
            ->whereKey($projectId)
            ->value('name');
    }

    private function tokenHash(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    private function payloadHash(int $organizationId, int $employeeId, ?int $projectId, string $workDate): string
    {
        return hash('sha256', implode('|', [$organizationId, $employeeId, $projectId ?? 'all', $workDate]));
    }

    private function rejected(?object $token, string $message, string $code, int $statusCode = 422): array
    {
        return [
            'rejected' => true,
            'token' => $token,
            'message' => $message,
            'code' => $code,
            'status_code' => $statusCode,
        ];
    }

    private function logScan(
        int $organizationId,
        ?object $token,
        int $scannerId,
        string $result,
        ?string $failureReason,
        ?string $deviceId,
        string $source,
        mixed $scannedAt = null
    ): int
    {
        $scannedAt ??= now();

        return (int) DB::table('workforce_attendance_scan_events')->insertGetId([
            'qr_token_id' => $token?->id,
            'organization_id' => $organizationId,
            'employee_id' => $token?->employee_id,
            'project_id' => $token?->project_id,
            'scanned_by_user_id' => $scannerId,
            'work_date' => $token?->work_date,
            'result' => $result,
            'result_label' => $result === 'confirmed'
                ? trans_message('workforce.attendance.qr_status_confirmed')
                : trans_message('workforce.attendance.qr_status_rejected'),
            'failure_reason' => $failureReason,
            'device_id' => $deviceId,
            'scanned_at' => $scannedAt,
            'metadata' => json_encode(['source' => $source], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function confirmedAttendanceExists(int $organizationId, int $employeeId, string $workDate, ?int $projectId): bool
    {
        return DB::table('workforce_attendance_scan_events')
            ->where('organization_id', $organizationId)
            ->where('employee_id', $employeeId)
            ->where('result', 'confirmed')
            ->whereDate('work_date', $workDate)
            ->where(function ($query) use ($projectId): void {
                if ($projectId === null) {
                    $query->whereNull('project_id');
                    return;
                }

                $query->where('project_id', $projectId);
            })
            ->exists();
    }

    private function historyPayload(object $scan, WorkforceEmployee $employee): array
    {
        $source = $this->scanSource($scan->metadata);

        return [
            'scan_event_id' => (int) $scan->id,
            'employee_id' => (int) $scan->employee_id,
            'employee_label' => $employee->full_name,
            'project_id' => $scan->project_id !== null ? (int) $scan->project_id : null,
            'project_label' => $scan->project_label,
            'work_date' => (string) $scan->work_date,
            'status' => 'at_work',
            'status_label' => trans_message('workforce.presence.at_work'),
            'source' => $source,
            'source_label' => trans_message('workforce.presence_sources.' . $source),
            'confirmed_at' => CarbonImmutable::parse((string) $scan->scanned_at)->toIso8601String(),
        ];
    }

    private function scanSource(mixed $metadata): string
    {
        $decoded = is_string($metadata) ? json_decode($metadata, true) : $metadata;

        if (is_array($decoded) && ($decoded['source'] ?? null) === 'self_attendance') {
            return 'self_attendance';
        }

        return 'qr_scan';
    }
}
