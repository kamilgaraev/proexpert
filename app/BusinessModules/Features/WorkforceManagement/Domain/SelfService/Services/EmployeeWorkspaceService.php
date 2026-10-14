<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\SelfService\Services;

use App\BusinessModules\Features\TimeTracking\Support\WorkingTimezoneResolver;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\EmploymentContract;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\HrDocument;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services\HrAccess;
use App\BusinessModules\Features\WorkforceManagement\Domain\SelfService\Http\EmployeeRequestForm;
use App\BusinessModules\Features\WorkforceManagement\Domain\SelfService\Http\RequestListForm;
use App\BusinessModules\Features\WorkforceManagement\Domain\SelfService\Models\EmployeeRequest;
use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services\TimekeepingService;
use App\Models\File;
use App\Models\User;
use App\Services\Storage\FileService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class EmployeeWorkspaceService
{
    public function __construct(private readonly HrAccess $access, private readonly RequestNotificationPublisher $publisher) {}

    public function employee(int $organizationId, ?User $actor, string $permission = 'workforce.self-service.view'): WorkforceEmployee
    {
        $this->access->assert($organizationId, $actor, $permission);
        $employees = WorkforceEmployee::query()->where('organization_id', $organizationId)->where('user_id', $actor->id)
            ->where('employment_status', 'active')->limit(2)->get();
        if ($employees->count() !== 1) {
            throw new AuthorizationException(trans_message('workforce.errors.self_service_unavailable'));
        }

        return $employees->first();
    }

    public function overview(int $organizationId, ?User $actor, string $month, ?string $workDate = null): array
    {
        $workDate ??= now()->toDateString();
        Validator::make(['month' => $month, 'work_date' => $workDate], ['month' => ['required', 'date_format:Y-m'], 'work_date' => ['required', 'date_format:Y-m-d']])->validate();
        $employee = $this->employee($organizationId, $actor);
        $workingTimezone = app(WorkingTimezoneResolver::class)->resolve($organizationId, (int) $actor->id, $workDate);
        if (! in_array($workingTimezone['timezone'], timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)) {
            $workingTimezone = ['timezone' => null, 'employment_contract_id' => null];
        }
        $timezoneOptions = EmploymentContract::query()->where('organization_id', $organizationId)->where('employee_id', $employee->id)
            ->where('relation_type', 'employment')->whereIn('status', ['active', 'closed'])->whereDate('start_date', '<=', $workDate)
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $workDate))
            ->orderBy('id')->get(['id', 'contract_number', 'timezone'])->filter(fn (EmploymentContract $contract): bool => is_string($contract->timezone) && in_array($contract->timezone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true))
            ->map(fn (EmploymentContract $contract): array => ['employment_contract_id' => $contract->id, 'contract_number' => $contract->contract_number, 'timezone' => $contract->timezone])->values()->all();
        if (! in_array($workingTimezone['employment_contract_id'], array_column($timezoneOptions, 'employment_contract_id'), true)) {
            $workingTimezone['employment_contract_id'] = null;
        }

        return [
            'employee' => ['id' => $employee->id, 'full_name' => $employee->full_name,
                'personnel_number' => $employee->personnel_number, 'employment_status' => $employee->employment_status,
                'working_timezone' => $workingTimezone['timezone'], 'working_timezone_date' => $workDate,
                'working_contract_id' => $workingTimezone['employment_contract_id'], 'working_timezones' => $timezoneOptions],
            'documents' => HrDocument::query()->where('organization_id', $organizationId)->where('employee_id', $employee->id)
                ->orderByDesc('id')->limit(200)->get()->map(static fn (HrDocument $document): array => [
                    'id' => $document->id, 'title' => $document->title, 'document_type' => $document->document_type,
                    'revision' => $document->revision, 'evidence_kind' => $document->evidence_kind,
                    'acknowledged_at' => $document->acknowledged_at?->toIso8601String(),
                ])->all(),
            'requests' => EmployeeRequest::query()->where('organization_id', $organizationId)->where('employee_id', $employee->id)
                ->orderByDesc('id')->limit(50)->get()->map(fn (EmployeeRequest $request): array => $this->present($request))->all(),
            'monthly_facts' => app(TimekeepingService::class)->ownApproved($organizationId, $actor, (int) $employee->id, $month),
        ];
    }

    public function requests(int $organizationId, ?User $actor, array $filters = []): array
    {
        $employee = $this->employee($organizationId, $actor);
        $data = Validator::make($filters, (new RequestListForm)->rules())->validate();
        $query = EmployeeRequest::query()->where('organization_id', $organizationId)->where('employee_id', $employee->id);
        $pendingCount = (clone $query)->whereIn('status', ['submitted', 'awaiting_hr'])->count();
        $status = $data['status'] ?? 'all';
        if ($status === 'pending') {
            $query->whereIn('status', ['submitted', 'awaiting_hr']);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }
        $page = $query->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 50), ['*'], 'page', (int) ($data['page'] ?? 1));

        return ['data' => $page->getCollection()->map(fn (EmployeeRequest $request): array => $this->present($request))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                'last_page' => $page->lastPage(), 'from' => $page->firstItem(), 'to' => $page->lastItem(), 'pending_count' => $pendingCount]];
    }

    public function download(int $organizationId, ?User $actor, int $documentId): array
    {
        $employee = $this->employee($organizationId, $actor);
        $document = HrDocument::query()->where('organization_id', $organizationId)->where('employee_id', $employee->id)->findOrFail($documentId);
        $file = File::query()->where('organization_id', $organizationId)->findOrFail($document->file_id);
        if ($file->disk !== 's3' || ($file->additional_info['visibility'] ?? null) !== 'private'
            || (int) ($file->additional_info['hr_employee_id'] ?? 0) !== (int) $employee->id
            || $file->fileable_type !== WorkforceEmployee::class || (int) $file->fileable_id !== (int) $employee->id
            || ! str_starts_with($file->path, 'org-'.$organizationId.'/workforce/hr/'.$employee->id.'/')) {
            throw new DomainException(trans_message('workforce.errors.hr_file_invalid'));
        }

        return ['url' => app(FileService::class)->temporaryDownloadUrl($file->path, 120), 'expires_in' => 120];
    }

    public function submit(int $organizationId, ?User $actor, array $payload): array
    {
        $employee = $this->employee($organizationId, $actor, 'workforce.self-service.request');
        $data = Validator::make($payload, (new EmployeeRequestForm)->rules())->validate();
        $data['basis'] = trim($data['basis']);
        $data['requested_data'] = array_intersect_key($data['requested_data'], array_flip(['absence_type_code', 'status', 'hours', 'project_id']));
        if ($data['basis'] === '' || ($data['kind'] === 'correction' && $data['date_from'] !== $data['date_to'])
            || ($employee->hire_date !== null && $data['date_from'] < $employee->hire_date->format('Y-m-d'))) {
            throw new DomainException(trans_message('workforce.errors.request_invalid'));
        }
        if (! empty($data['requested_data']['project_id']) && ! DB::table('projects')->where('organization_id', $organizationId)->where('id', $data['requested_data']['project_id'])->whereNull('deleted_at')->exists()) {
            throw new DomainException(trans_message('workforce.errors.project_not_found'));
        }

        return DB::transaction(function () use ($organizationId, $actor, $employee, $data): array {
            // Serialise retries for this employee; the unique key protects concurrent duplicate creation.
            $lockedEmployee = WorkforceEmployee::query()->where('organization_id', $organizationId)->lockForUpdate()->findOrFail($employee->id);
            if ($lockedEmployee->employment_status !== 'active' || (int) $lockedEmployee->user_id !== (int) $actor->id) {
                throw new AuthorizationException(trans_message('workforce.errors.self_service_unavailable'));
            }
            $existing = EmployeeRequest::query()->where('organization_id', $organizationId)->where('requested_by', $actor->id)
                ->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                foreach (['kind', 'date_from', 'date_to', 'basis', 'requested_data'] as $key) {
                    if ($data[$key] != $existing->$key) {
                        throw new DomainException(trans_message('workforce.errors.request_invalid'));
                    }
                }

                return $this->present($existing);
            }
            $request = EmployeeRequest::create($data + ['organization_id' => $organizationId, 'employee_id' => $employee->id, 'requested_by' => $actor->id]);
            $request->refresh();
            $this->notify($request);

            return $this->present($request);
        });
    }

    public function notify(EmployeeRequest $request): void
    {
        $this->publisher->enqueue($request, (int) $request->requested_by, 'mobile');
        $recipients = array_map('intval', $this->managerIds((int) $request->organization_id, (int) $request->employee_id));
        if ($request->status === 'awaiting_hr') {
            foreach (User::query()->whereHas('organizations', fn ($query) => $query->where('organizations.id', $request->organization_id)->where('organization_user.is_active', true))->cursor() as $user) {
                if ($this->access->allows((int) $request->organization_id, $user, 'workforce.hr.manage')) {
                    $recipients[] = (int) $user->id;
                }
            }
        }
        foreach (array_unique($recipients) as $userId) {
            $this->publisher->enqueue($request, $userId, 'admin');
        }
    }

    public function managerIds(int $organizationId, int $employeeId): array
    {
        return DB::table('workforce_team_scopes')->where('organization_id', $organizationId)->where('employee_id', $employeeId)
            ->where('valid_from', '<=', now()->toDateString())->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', now()->toDateString()))
            ->pluck('manager_user_id')->all();
    }

    public function present(EmployeeRequest $request): array
    {
        return $request->only(['id', 'employee_id', 'kind', 'date_from', 'date_to', 'basis', 'requested_data', 'status',
            'manager_decision_basis', 'hr_decision_basis', 'comments', 'result', 'revision', 'created_at', 'updated_at']);
    }
}
