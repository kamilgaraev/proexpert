<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Services;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
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
        $workDate = isset($payload['work_date'])
            ? CarbonImmutable::parse((string) $payload['work_date'])->toDateString()
            : CarbonImmutable::now()->toDateString();
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
                return $this->rejected(null, trans_message('workforce.errors.qr_token_invalid'));
            }

            if ((string) $token->status === 'used' || $token->used_at !== null) {
                return $this->rejected($token, trans_message('workforce.errors.qr_token_already_used'));
            }

            if ((string) $token->status !== 'active') {
                return $this->rejected($token, trans_message('workforce.errors.qr_token_invalid'));
            }

            if (CarbonImmutable::parse((string) $token->expires_at)->isPast()) {
                DB::table('workforce_attendance_qr_tokens')
                    ->where('id', $token->id)
                    ->update(['status' => 'expired', 'updated_at' => now()]);
                return $this->rejected($token, trans_message('workforce.errors.qr_token_expired'));
            }

            $employee = $this->employee($organizationId, (int) $token->employee_id);
            $workDate = (string) $token->work_date;

            if (! $this->employeeActiveOnDate($employee, $workDate)) {
                return $this->rejected($token, trans_message('workforce.errors.qr_employee_inactive'));
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

            $scanId = $this->logScan($organizationId, $token, (int) $scanner->id, 'confirmed', null, $deviceId);

            return [
                'scan_event_id' => $scanId,
                'employee_id' => (int) $employee->id,
                'employee_label' => $employee->full_name,
                'project_id' => $token->project_id !== null ? (int) $token->project_id : null,
                'project_label' => $token->project_id !== null ? $this->projectLabel($organizationId, (int) $token->project_id) : null,
                'work_date' => $workDate,
                'status' => 'at_work',
                'status_label' => trans_message('workforce.attendance.qr_status_confirmed'),
                'source_label' => trans_message('workforce.attendance.qr_source_label'),
                'confirmed_at' => now()->toIso8601String(),
            ];
        });

        if (($result['rejected'] ?? false) === true) {
            $this->logScan($organizationId, $result['token'], (int) $scanner->id, 'rejected', (string) $result['message'], $deviceId);
            throw new DomainException((string) $result['message']);
        }

        return $result;
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

    private function rejected(?object $token, string $message): array
    {
        return [
            'rejected' => true,
            'token' => $token,
            'message' => $message,
        ];
    }

    private function logScan(int $organizationId, ?object $token, int $scannerId, string $result, ?string $failureReason, ?string $deviceId): int
    {
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
            'scanned_at' => now(),
            'metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
