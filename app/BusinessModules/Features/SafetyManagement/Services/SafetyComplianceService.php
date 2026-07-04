<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Services;

use App\BusinessModules\Features\SafetyManagement\DTOs\SafetyComplianceContext;
use App\BusinessModules\Features\SafetyManagement\DTOs\SafetyComplianceRequirementResult;
use App\BusinessModules\Features\SafetyManagement\DTOs\SafetyComplianceResult;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyBriefingParticipant;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyEmployeeRequirement;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyMedicalExam;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyPpeIssue;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyPpeNorm;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyRequirementMatrix;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyTrainingRecord;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

final class SafetyComplianceService
{
    public function check(SafetyComplianceContext $context): SafetyComplianceResult
    {
        $date = $context->date === null
            ? CarbonImmutable::today()
            : CarbonImmutable::instance($context->date);
        $employee = $this->findEmployee($context);
        $matrices = $this->matchingMatrices($context, $date);
        $requirements = $this->requirementsFromMatrices($matrices);
        $requirements = $this->mergeRequirements($requirements, $this->requirementsFromPpeNorms($context));
        $results = [];
        $blockers = [];
        $warnings = [];

        if ($matrices === []) {
            $blockers[] = $this->flag('matrix_not_configured', 'critical');
        }

        foreach ($this->employeeLifecycleFlags($employee, $date) as $flag) {
            $blockers[] = $flag;
        }

        foreach ($requirements as $requirement) {
            $result = $this->evaluateRequirement($context, $employee, $requirement, $date);
            $results[] = $result;

            if (in_array($result->status, ['missing', 'expired', 'failed', 'not_fit'], true)) {
                $target = ($requirement['required'] ?? true) ? 'blockers' : 'warnings';
                ${$target}[] = $this->flag($this->flagCode($result), $result->severity, $result);
            }

            if ($result->status === 'restricted') {
                $warnings[] = $this->flag('medical_exam_restrictions', 'warning', $result);
            }

            if ($this->isExpiringSoon($result->validUntil, $date)) {
                $warnings[] = $this->flag('requirement_expires_soon', 'warning', $result);
            }
        }

        $status = $blockers === [] ? ($warnings === [] ? 'admitted' : 'partial') : 'not_admitted';

        return new SafetyComplianceResult(
            employeeId: (int) $employee->id,
            status: $status,
            statusLabel: trans_message("safety_management.admission_statuses.{$status}"),
            blocked: $blockers !== [],
            expiresSoon: $this->hasExpiringRequirements($results, $date),
            requirements: $results,
            blockers: $blockers,
            warnings: $warnings,
            checkedAt: CarbonImmutable::now(),
        );
    }

    private function findEmployee(SafetyComplianceContext $context): WorkforceEmployee
    {
        $employee = WorkforceEmployee::query()
            ->where('organization_id', $context->organizationId)
            ->whereKey($context->employeeId)
            ->first();

        if (!$employee instanceof WorkforceEmployee) {
            throw new DomainException(trans_message('safety_management.errors.employee_not_found'));
        }

        return $employee;
    }

    private function matchingMatrices(SafetyComplianceContext $context, CarbonInterface $date): array
    {
        if ($context->workCategory === null) {
            return [];
        }

        return SafetyRequirementMatrix::query()
            ->forOrganization($context->organizationId)
            ->where('is_active', true)
            ->where('work_category', $context->workCategory)
            ->where(static function (Builder $query) use ($date): void {
                $query->whereNull('effective_from')
                    ->orWhereDate('effective_from', '<=', $date->toDateString());
            })
            ->where(static function (Builder $query) use ($date): void {
                $query->whereNull('effective_until')
                    ->orWhereDate('effective_until', '>=', $date->toDateString());
            })
            ->when(
                $context->projectId !== null,
                static fn (Builder $query): Builder => $query->where(static function (Builder $query) use ($context): void {
                    $query->whereNull('project_id')->orWhere('project_id', $context->projectId);
                }),
                static fn (Builder $query): Builder => $query->whereNull('project_id'),
            )
            ->when(
                $context->workTypeId !== null,
                static fn (Builder $query): Builder => $query->where(static function (Builder $query) use ($context): void {
                    $query->whereNull('work_type_id')->orWhere('work_type_id', $context->workTypeId);
                }),
                static fn (Builder $query): Builder => $query->whereNull('work_type_id'),
            )
            ->when(
                $context->positionName !== null,
                static fn (Builder $query): Builder => $query->where(static function (Builder $query) use ($context): void {
                    $query->whereNull('position_name')->orWhere('position_name', $context->positionName);
                }),
                static fn (Builder $query): Builder => $query->whereNull('position_name'),
            )
            ->get()
            ->sortBy(static function (SafetyRequirementMatrix $matrix) use ($context): int {
                return ($matrix->project_id === $context->projectId ? 4 : 0)
                    + ($matrix->work_type_id === $context->workTypeId ? 2 : 0)
                    + ($matrix->position_name === $context->positionName ? 1 : 0);
            })
            ->values()
            ->all();
    }

    private function requirementsFromMatrices(array $matrices): array
    {
        $requirements = [];

        foreach ($matrices as $matrix) {
            if (!$matrix instanceof SafetyRequirementMatrix) {
                continue;
            }

            $requirements = $this->mergeRequirements($requirements, $this->normalizeRequirements($matrix->requirements ?? []));
        }

        return $requirements;
    }

    private function requirementsFromPpeNorms(SafetyComplianceContext $context): array
    {
        if ($context->workCategory === null && $context->positionName === null) {
            return [];
        }

        return SafetyPpeNorm::query()
            ->forOrganization($context->organizationId)
            ->where('is_required', true)
            ->when($context->workCategory !== null, static function (Builder $query) use ($context): void {
                $query->where(static function (Builder $query) use ($context): void {
                    $query->whereNull('work_category')->orWhere('work_category', $context->workCategory);
                });
            }, static fn (Builder $query): Builder => $query->whereNull('work_category'))
            ->when($context->positionName !== null, static function (Builder $query) use ($context): void {
                $query->where(static function (Builder $query) use ($context): void {
                    $query->whereNull('position_name')->orWhere('position_name', $context->positionName);
                });
            }, static fn (Builder $query): Builder => $query->whereNull('position_name'))
            ->get()
            ->map(static fn (SafetyPpeNorm $norm): array => [
                'type' => 'ppe',
                'code' => $norm->ppe_code,
                'label' => $norm->ppe_name,
                'required' => true,
            ])
            ->all();
    }

    private function normalizeRequirements(array $requirements): array
    {
        if ($requirements === []) {
            return [];
        }

        $normalized = [];

        if (array_is_list($requirements)) {
            foreach ($requirements as $item) {
                $entry = $this->normalizeRequirementEntry(is_array($item) ? $item : []);
                if ($entry !== null) {
                    $normalized[] = $entry;
                }
            }

            return $normalized;
        }

        foreach ($requirements as $type => $items) {
            if (!is_array($items)) {
                continue;
            }

            if (!array_is_list($items)) {
                $items = [$items];
            }

            foreach ($items as $item) {
                if (is_string($item)) {
                    $item = ['code' => $item, 'label' => $item];
                }

                $entry = $this->normalizeRequirementEntry(is_array($item) ? ['type' => (string) $type] + $item : []);
                if ($entry !== null) {
                    $normalized[] = $entry;
                }
            }
        }

        return $normalized;
    }

    private function normalizeRequirementEntry(array $item): ?array
    {
        $type = isset($item['type']) ? trim((string) $item['type']) : '';
        $code = isset($item['code']) ? trim((string) $item['code']) : '';

        if ($type === '' || $code === '') {
            return null;
        }

        return [
            'type' => $type,
            'code' => $code,
            'label' => trim((string) ($item['label'] ?? $code)),
            'required' => (bool) ($item['required'] ?? true),
        ];
    }

    private function mergeRequirements(array $left, array $right): array
    {
        $merged = [];

        foreach ([...$left, ...$right] as $requirement) {
            $key = "{$requirement['type']}:{$requirement['code']}";
            $merged[$key] = [
                'type' => $requirement['type'],
                'code' => $requirement['code'],
                'label' => $requirement['label'] ?? $requirement['code'],
                'required' => ($merged[$key]['required'] ?? false) || (bool) ($requirement['required'] ?? true),
            ];
        }

        return array_values($merged);
    }

    private function employeeLifecycleFlags(WorkforceEmployee $employee, CarbonInterface $date): array
    {
        $flags = [];

        if ($employee->employment_status !== 'active') {
            $flags[] = $this->flag('employee_inactive', 'critical');
        }

        if ($employee->hire_date !== null && $employee->hire_date->greaterThan($date)) {
            $flags[] = $this->flag('employee_not_hired', 'critical');
        }

        if ($employee->dismissal_date !== null && $employee->dismissal_date->lessThanOrEqualTo($date)) {
            $flags[] = $this->flag('employee_dismissed', 'critical');
        }

        return $flags;
    }

    private function evaluateRequirement(
        SafetyComplianceContext $context,
        WorkforceEmployee $employee,
        array $requirement,
        CarbonInterface $date
    ): SafetyComplianceRequirementResult {
        $override = $this->validEmployeeRequirement($context, $requirement, $date);

        if ($override instanceof SafetyEmployeeRequirement) {
            return $this->result($requirement, 'fulfilled', 'ok', $override->valid_until, 'employee_requirement', (int) $override->id);
        }

        return match ($requirement['type']) {
            'training' => $this->evaluateTraining($context, $requirement, $date),
            'medical_exam' => $this->evaluateMedicalExam($context, $requirement, $date),
            'ppe' => $this->evaluatePpe($context, $requirement, $date),
            'briefing' => $this->evaluateBriefing($context, $employee, $requirement, $date),
            default => $this->result($requirement, 'missing', $this->severity($requirement)),
        };
    }

    private function validEmployeeRequirement(
        SafetyComplianceContext $context,
        array $requirement,
        CarbonInterface $date
    ): ?SafetyEmployeeRequirement {
        return SafetyEmployeeRequirement::query()
            ->forOrganization($context->organizationId)
            ->where('employee_id', $context->employeeId)
            ->where('requirement_type', $requirement['type'])
            ->where('requirement_code', $requirement['code'])
            ->whereIn('status', ['fulfilled', 'valid', 'approved', 'completed'])
            ->where(static function (Builder $query) use ($date): void {
                $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', $date->toDateString());
            })
            ->where(static function (Builder $query) use ($date): void {
                $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $date->toDateString());
            })
            ->when(
                $context->projectId !== null,
                static fn (Builder $query): Builder => $query->where(static function (Builder $query) use ($context): void {
                    $query->whereNull('project_id')->orWhere('project_id', $context->projectId);
                }),
                static fn (Builder $query): Builder => $query->whereNull('project_id'),
            )
            ->when(
                $context->workTypeId !== null,
                static fn (Builder $query): Builder => $query->where(static function (Builder $query) use ($context): void {
                    $query->whereNull('work_type_id')->orWhere('work_type_id', $context->workTypeId);
                }),
                static fn (Builder $query): Builder => $query->whereNull('work_type_id'),
            )
            ->latest('valid_until')
            ->first();
    }

    private function evaluateTraining(
        SafetyComplianceContext $context,
        array $requirement,
        CarbonInterface $date
    ): SafetyComplianceRequirementResult {
        $record = SafetyTrainingRecord::query()
            ->forOrganization($context->organizationId)
            ->where('employee_id', $context->employeeId)
            ->where('program_code', $requirement['code'])
            ->orderByDesc('valid_until')
            ->orderByDesc('completed_at')
            ->first();

        if (!$record instanceof SafetyTrainingRecord) {
            return $this->result($requirement, 'missing', $this->severity($requirement));
        }

        if ($record->result !== 'passed') {
            return $this->result($requirement, 'failed', $this->severity($requirement), $record->valid_until, 'training', (int) $record->id);
        }

        if (!$this->validOn($record->valid_until, $date)) {
            return $this->result($requirement, 'expired', $this->severity($requirement), $record->valid_until, 'training', (int) $record->id);
        }

        return $this->result($requirement, 'fulfilled', 'ok', $record->valid_until, 'training', (int) $record->id);
    }

    private function evaluateMedicalExam(
        SafetyComplianceContext $context,
        array $requirement,
        CarbonInterface $date
    ): SafetyComplianceRequirementResult {
        $exam = SafetyMedicalExam::query()
            ->forOrganization($context->organizationId)
            ->where('employee_id', $context->employeeId)
            ->where('exam_type', $requirement['code'])
            ->orderByDesc('valid_until')
            ->orderByDesc('completed_at')
            ->first();

        if (!$exam instanceof SafetyMedicalExam) {
            return $this->result($requirement, 'missing', $this->severity($requirement));
        }

        if ($exam->result === 'not_fit') {
            return $this->result($requirement, 'not_fit', $this->severity($requirement), $exam->valid_until, 'medical_exam', (int) $exam->id);
        }

        if (!$this->validOn($exam->valid_until, $date)) {
            return $this->result($requirement, 'expired', $this->severity($requirement), $exam->valid_until, 'medical_exam', (int) $exam->id);
        }

        if ($exam->result === 'fit_with_restrictions') {
            return $this->result($requirement, 'restricted', 'warning', $exam->valid_until, 'medical_exam', (int) $exam->id);
        }

        return $this->result($requirement, 'fulfilled', 'ok', $exam->valid_until, 'medical_exam', (int) $exam->id);
    }

    private function evaluatePpe(
        SafetyComplianceContext $context,
        array $requirement,
        CarbonInterface $date
    ): SafetyComplianceRequirementResult {
        $issue = SafetyPpeIssue::query()
            ->forOrganization($context->organizationId)
            ->where('employee_id', $context->employeeId)
            ->where('ppe_code', $requirement['code'])
            ->where('status', 'issued')
            ->orderByDesc('valid_until')
            ->orderByDesc('issued_at')
            ->first();

        if (!$issue instanceof SafetyPpeIssue) {
            return $this->result($requirement, 'missing', $this->severity($requirement));
        }

        if (!$this->validOn($issue->valid_until, $date)) {
            return $this->result($requirement, 'expired', $this->severity($requirement), $issue->valid_until, 'ppe', (int) $issue->id);
        }

        return $this->result($requirement, 'fulfilled', 'ok', $issue->valid_until, 'ppe', (int) $issue->id);
    }

    private function evaluateBriefing(
        SafetyComplianceContext $context,
        WorkforceEmployee $employee,
        array $requirement,
        CarbonInterface $date
    ): SafetyComplianceRequirementResult {
        if ($employee->user_id === null) {
            return $this->result($requirement, 'missing', $this->severity($requirement));
        }

        $participant = SafetyBriefingParticipant::query()
            ->where('user_id', $employee->user_id)
            ->whereNotNull('signed_at')
            ->whereHas('briefing', static function (Builder $query) use ($context, $requirement, $date): void {
                $query->where('organization_id', $context->organizationId)
                    ->where('briefing_type', $requirement['code'])
                    ->whereDate('conducted_at', '<=', $date->toDateString())
                    ->when($context->projectId !== null, static fn (Builder $query): Builder => $query->where('project_id', $context->projectId));
            })
            ->latest('signed_at')
            ->first();

        if (!$participant instanceof SafetyBriefingParticipant) {
            return $this->result($requirement, 'missing', $this->severity($requirement));
        }

        return $this->result($requirement, 'fulfilled', 'ok', $participant->signed_at, 'briefing', (int) $participant->id);
    }

    private function result(
        array $requirement,
        string $status,
        string $severity,
        ?CarbonInterface $validUntil = null,
        ?string $sourceType = null,
        ?int $sourceId = null
    ): SafetyComplianceRequirementResult {
        return new SafetyComplianceRequirementResult(
            code: $requirement['code'],
            type: $requirement['type'],
            label: $requirement['label'] ?? $requirement['code'],
            status: $status,
            severity: $severity,
            sourceType: $sourceType,
            sourceId: $sourceId,
            validUntil: $validUntil,
            message: null,
        );
    }

    private function flag(string $code, string $severity, ?SafetyComplianceRequirementResult $result = null): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'message' => trans_message("safety_management.problem_flags.{$code}"),
            'requirement_type' => $result?->type,
            'requirement_code' => $result?->code,
            'requirement_label' => $result?->label,
            'source_type' => $result?->sourceType,
            'source_id' => $result?->sourceId,
        ];
    }

    private function flagCode(SafetyComplianceRequirementResult $result): string
    {
        return match ($result->type) {
            'training' => match ($result->status) {
                'expired' => 'training_expired',
                'failed' => 'training_failed',
                default => 'training_missing',
            },
            'medical_exam' => match ($result->status) {
                'expired' => 'medical_exam_expired',
                'not_fit' => 'medical_exam_not_fit',
                default => 'medical_exam_missing',
            },
            'ppe' => $result->status === 'expired' ? 'ppe_expired' : 'ppe_missing',
            'briefing' => 'briefing_missing',
            default => 'requirement_missing',
        };
    }

    private function severity(array $requirement): string
    {
        return ($requirement['required'] ?? true) ? 'critical' : 'warning';
    }

    private function validOn(?CarbonInterface $validUntil, CarbonInterface $date): bool
    {
        return $validUntil === null || $validUntil->greaterThanOrEqualTo($date);
    }

    private function isExpiringSoon(?CarbonInterface $validUntil, CarbonInterface $date): bool
    {
        if ($validUntil === null) {
            return false;
        }

        return $validUntil->greaterThanOrEqualTo($date)
            && $validUntil->lessThanOrEqualTo($date->copy()->addDays(30));
    }

    private function hasExpiringRequirements(array $results, CarbonInterface $date): bool
    {
        foreach ($results as $result) {
            if ($result instanceof SafetyComplianceRequirementResult && $this->isExpiringSoon($result->validUntil, $date)) {
                return true;
            }
        }

        return false;
    }
}
