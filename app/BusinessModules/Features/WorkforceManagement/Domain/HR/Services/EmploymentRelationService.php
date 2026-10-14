<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\EmploymentContract;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\HrDocument;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\HrEvent;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\Models\Organization;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class EmploymentRelationService
{
    public function __construct(private readonly HrAccess $access) {}

    public function history(int $organizationId, int $employeeId, ?User $actor): array
    {
        $this->access->assert($organizationId, $actor);
        $employee = $this->employee($organizationId, $employeeId);
        return [
            'reconciliation_status' => $employee->hr_reconciliation_status,
            'relations' => EmploymentContract::query()->where('organization_id', $organizationId)->where('employee_id', $employeeId)->orderBy('start_date')->get()->map(fn (EmploymentContract $relation): array => $this->safeState($relation->toArray())),
            'events' => HrEvent::query()->where('organization_id', $organizationId)->where('employee_id', $employeeId)->orderBy('effective_date')->orderBy('id')->get(),
        ];
    }

    public function record(int $organizationId, int $employeeId, array $payload, ?User $actor): EmploymentContract
    {
        $this->access->assert($organizationId, $actor);
        $payload = Validator::make($payload, [
            'relation_type' => ['required', 'in:employment,civil'], 'employment_mode' => ['nullable', 'in:main,internal_secondary,external_secondary,part_time'],
            'contract_number' => ['required', 'string', 'max:120'], 'contract_date' => ['required', 'date_format:Y-m-d'], 'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'], 'job_share' => ['nullable', 'numeric', 'gt:0', 'lte:1'],
            'timezone' => ['nullable', 'timezone'], 'weekly_norm_hours' => ['nullable', 'numeric', 'gt:0', 'lte:40'],
            'summed_accounting_period' => ['nullable', 'in:month,quarter,year'], 'calendar_profile' => ['nullable', 'string', 'max:80'],
            'supersedes_id' => ['nullable', 'integer'], 'event_type' => ['required', 'in:hire,change,transfer,reconcile'],
            'basis_reference' => ['required', 'string', 'max:255'], 'basis_document_id' => ['nullable', 'integer'],
        ])->validate();
        foreach (['supersedes_id', 'basis_document_id'] as $idField) {
            if (isset($payload[$idField])) {
                $payload[$idField] = (int) $payload[$idField];
            }
        }
        EmploymentTerms::assert($payload);
        if (! in_array($payload['event_type'] ?? '', ['hire', 'change', 'transfer', 'reconcile'], true) || empty($payload['basis_reference'])) {
            throw new DomainException(trans_message('workforce.errors.hr_basis_required'));
        }
        return DB::transaction(function () use ($organizationId, $employeeId, $payload, $actor): EmploymentContract {
            Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();
            $employee = $this->employee($organizationId, $employeeId);
            if ($employee->employment_status === 'dismissed') {
                throw new DomainException(trans_message('workforce.errors.workflow_transition_forbidden'));
            }
            $this->basis($organizationId, $employeeId, $payload['basis_document_id'] ?? null);
            $previous = isset($payload['supersedes_id']) ? EmploymentContract::query()->where('organization_id', $organizationId)
                ->where('employee_id', $employeeId)->lockForUpdate()->findOrFail($payload['supersedes_id']) : null;
            $reconcileSameStart = $previous && $previous->relation_type === null && $payload['event_type'] === 'reconcile'
                && $previous->start_date->toDateString() === $payload['start_date'];
            if ($previous && (EmploymentContract::query()->where('supersedes_id', $previous->id)->exists() || $previous->status !== 'active' || (! $reconcileSameStart && $previous->start_date->toDateString() >= $payload['start_date'])
                || ($previous->end_date !== null && $previous->end_date->toDateString() < $payload['start_date']))) {
                throw new DomainException(trans_message('workforce.errors.workflow_transition_forbidden'));
            }
            if (($payload['event_type'] !== 'reconcile' || $previous === null) && EmploymentContract::query()->where('organization_id', $organizationId)->where('employee_id', $employeeId)->whereNull('relation_type')->where('status', 'active')
                ->when($previous, fn ($query) => $query->whereKeyNot($previous->id))->exists()) {
                throw new DomainException(trans_message('workforce.errors.hr_reconciliation_required'));
            }
            if (in_array($payload['event_type'], ['change', 'transfer'], true) && $previous === null) {
                throw new DomainException(trans_message('workforce.errors.hr_relation_invalid'));
            }
            // Frozen payroll periods retain their effective source; retrospective changes require an explicit correction workflow.
            if (DB::table('workforce_payroll_periods')->where('organization_id', $organizationId)->where('status', 'locked')
                ->whereDate('period_end', '>=', $payload['start_date'])->exists()) {
                throw new DomainException(trans_message('workforce.errors.payroll_period_locked'));
            }
            $this->assertTimekeepingHistoryMutable($organizationId, $employeeId, $payload['start_date'], $payload['end_date'] ?? null);
            $conflict = EmploymentContract::query()->where('organization_id', $organizationId)->where('employee_id', $employeeId)
                ->where('status', 'active')->where('relation_type', $payload['relation_type'])
                ->when($payload['relation_type'] === 'employment', fn ($query) => $query->where('employment_mode', $payload['employment_mode']))
                ->when($previous, fn ($query) => $query->whereKeyNot($previous->id))
                ->where(function ($query) use ($payload): void { $query->whereNull('end_date')->orWhereDate('end_date', '>=', $payload['start_date']); })
                ->when(! empty($payload['end_date']), fn ($query) => $query->whereDate('start_date', '<=', $payload['end_date']))->exists();
            if ($conflict) {
                throw new DomainException(trans_message('workforce.errors.hr_relation_overlap'));
            }
            $before = $previous?->toArray();
            if ($previous) {
                $previous->update($reconcileSameStart
                    ? ['status' => 'superseded']
                    : ['end_date' => \Carbon\CarbonImmutable::parse($payload['start_date'])->subDay()->toDateString(), 'status' => 'active']);
            }
            $relation = EmploymentContract::create(array_merge(array_intersect_key($payload, array_flip([
                'relation_type', 'employment_mode', 'contract_number', 'contract_date', 'start_date', 'end_date', 'job_share', 'timezone', 'weekly_norm_hours', 'summed_accounting_period', 'calendar_profile', 'supersedes_id',
            ])), ['organization_id' => $organizationId, 'employee_id' => $employeeId, 'revision' => $previous ? $previous->revision + 1 : 1, 'created_by' => $actor->id, 'status' => 'active']));
            $legacyRemaining = EmploymentContract::query()->where('organization_id', $organizationId)->where('employee_id', $employeeId)
                ->where('status', 'active')->whereNull('relation_type')->exists();
            $employee->update(['hr_reconciliation_status' => $legacyRemaining ? 'needs_reconciliation' : 'reconciled']);
            $this->event($organizationId, $employeeId, $payload['event_type'], $payload['start_date'], $payload['basis_reference'], $payload['basis_document_id'] ?? null, $actor, $before, $relation->toArray(), (int) $relation->id);
            return $relation;
        });
    }

    public function basis(int $organizationId, int $employeeId, ?int $documentId): void
    {
        if ($documentId !== null && ! HrDocument::query()->where('organization_id', $organizationId)->where('employee_id', $employeeId)->whereKey($documentId)->exists()) {
            throw new DomainException(trans_message('workforce.errors.hr_document_not_found'));
        }
    }

    public function event(int $organizationId, int $employeeId, string $type, string $date, string $basis, ?int $documentId, User $actor, ?array $before, ?array $after, ?int $relationId = null): HrEvent
    {
        $this->access->assert($organizationId, $actor);
        $this->employee($organizationId, $employeeId);
        $this->basis($organizationId, $employeeId, $documentId);
        if ($relationId !== null) {
            EmploymentContract::query()->where('organization_id', $organizationId)->where('employee_id', $employeeId)->findOrFail($relationId);
        }
        if (! in_array($type, ['hire', 'change', 'transfer', 'reconcile', 'dismissal', 'personnel_card_created', 'personnel_card_changed'], true)) {
            throw new DomainException(trans_message('workforce.errors.workflow_transition_forbidden'));
        }
        if (trim($basis) === '') {
            throw new DomainException(trans_message('workforce.errors.hr_basis_required'));
        }
        return HrEvent::create(['organization_id' => $organizationId, 'employee_id' => $employeeId, 'relation_id' => $relationId, 'event_type' => $type, 'effective_date' => $date, 'basis_reference' => $basis, 'basis_document_id' => $documentId, 'before_state' => $before !== null ? $this->safeState($before) : null, 'after_state' => $after !== null ? $this->safeState($after) : null, 'recorded_by' => $actor->id, 'recorded_at' => now()]);
    }

    public function assertTimekeepingHistoryMutable(int $organizationId, int $employeeId, string $from, ?string $to = null): void
    {
        $query = DB::table('workforce_timekeeping_periods')->where('organization_id', $organizationId)
            ->whereIn('status', ['approved', 'closed'])
            ->whereNotExists(function ($successor): void {
                $successor->selectRaw('1')->from('workforce_timekeeping_periods as successor')
                    ->whereColumn('successor.supersedes_id', 'workforce_timekeeping_periods.id')
                    ->whereColumn('successor.organization_id', 'workforce_timekeeping_periods.organization_id');
            })
            ->whereDate('month', '>=', \Carbon\CarbonImmutable::parse($from)->startOfMonth()->toDateString())
            ->whereJsonContains('snapshot->rows', [['employee_id' => $employeeId]]);
        if ($to !== null) {
            $query->whereDate('month', '<=', \Carbon\CarbonImmutable::parse($to)->startOfMonth()->toDateString());
        }
        if ($query->exists()) {
            throw new DomainException(trans_message('workforce.errors.hr_history_frozen'));
        }
    }

    private function safeState(array $state): array
    {
        unset($state['metadata'], $state['phone'], $state['email'], $state['external_payroll_ref'], $state['user']);
        return $state;
    }

    private function employee(int $organizationId, int $employeeId): WorkforceEmployee
    {
        return WorkforceEmployee::query()->where('organization_id', $organizationId)->findOrFail($employeeId);
    }
}
