<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\SelfService\Services;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\HrDocument;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services\HrAccess;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services\HrDocumentService;
use App\BusinessModules\Features\WorkforceManagement\Domain\SelfService\Http\RequestDecisionForm;
use App\BusinessModules\Features\WorkforceManagement\Domain\SelfService\Http\RequestListForm;
use App\BusinessModules\Features\WorkforceManagement\Domain\SelfService\Models\EmployeeRequest;
use App\BusinessModules\Features\WorkforceManagement\Services\WorkforceAttendanceService;
use App\BusinessModules\Features\WorkforceManagement\Services\WorkforceProService;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class TeamQueueService
{
    public function __construct(private readonly HrAccess $access, private readonly EmployeeWorkspaceService $workspace, private readonly RequestNotificationPublisher $publisher) {}

    public function index(int $organizationId, ?User $actor, array $filters = []): array
    {
        $this->access->assert($organizationId, $actor, 'workforce.team-queue.view');
        $data = Validator::make($filters, (new RequestListForm)->rules())->validate();
        $hr = $this->access->allows($organizationId, $actor, 'workforce.hr.manage');

        $query = EmployeeRequest::query()->where('organization_id', $organizationId)
            ->when(! $hr, fn ($query) => $query->whereIn('employee_id', DB::table('workforce_team_scopes')->select('employee_id')
                ->where('organization_id', $organizationId)->where('manager_user_id', $actor->id)->where('valid_from', '<=', now()->toDateString())
                ->where(fn ($scope) => $scope->whereNull('valid_to')->orWhere('valid_to', '>=', now()->toDateString()))));
        $pendingCount = (clone $query)->whereIn('status', ['submitted', 'awaiting_hr'])->count();
        $status = $data['status'] ?? 'all';
        if ($status === 'pending') {
            $query->whereIn('status', ['submitted', 'awaiting_hr']);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }
        $page = $query->orderByRaw("CASE WHEN status IN ('submitted', 'awaiting_hr') THEN 0 ELSE 1 END")
            ->orderBy('id')->paginate((int) ($data['per_page'] ?? 25), ['*'], 'page', (int) ($data['page'] ?? 1));
        $requests = $page->getCollection();
        $employees = WorkforceEmployee::query()->where('organization_id', $organizationId)->whereIn('id', $requests->pluck('employee_id'))->get()->keyBy('id');

        $items = $requests->map(function (EmployeeRequest $request) use ($employees): array {
            $employee = $employees->get($request->employee_id);

            return $this->workspace->present($request) + ['employee_name' => $employee?->full_name];
        })->all();

        return ['data' => $items, 'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(),
            'total' => $page->total(), 'last_page' => $page->lastPage(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
            'summary' => ['pending_count' => $pendingCount]];
    }

    public function decide(int $organizationId, ?User $actor, int $requestId, array $payload): array
    {
        $data = Validator::make($payload, (new RequestDecisionForm)->rules())->validate();
        $data['basis'] = trim($data['basis']);
        if ($data['basis'] === '') {
            throw new DomainException(trans_message('workforce.errors.request_invalid'));
        }
        $hrAction = str_starts_with($data['action'], 'hr_');
        $this->access->assert($organizationId, $actor, $hrAction ? 'workforce.hr.manage' : 'workforce.team-queue.manage');

        return DB::transaction(function () use ($organizationId, $actor, $requestId, $data, $hrAction): array {
            $request = EmployeeRequest::query()->where('organization_id', $organizationId)->lockForUpdate()->findOrFail($requestId);
            if ((int) $request->requested_by === (int) $actor->id) {
                throw new AuthorizationException(trans_message('workforce.errors.team_scope_denied'));
            }
            $hrComment = $data['action'] === 'comment' && $this->access->allows($organizationId, $actor, 'workforce.hr.manage');
            if (! $hrAction && ! $hrComment && ! in_array((int) $actor->id, array_map('intval', $this->workspace->managerIds($organizationId, (int) $request->employee_id)), true)) {
                throw new AuthorizationException(trans_message('workforce.errors.team_scope_denied'));
            }
            $status = RequestWorkflow::next($request->status, $data['action']);
            if ($data['action'] === 'comment') {
                $request->comments = [...($request->comments ?? []), ['actor_id' => $actor->id, 'basis' => $data['basis'], 'created_at' => now()->toIso8601String()]];
            } elseif ($hrAction) {
                $request->hr_decision_basis = $data['basis'];
                $request->hr_decided_by = $actor->id;
                if ($data['action'] === 'hr_approve') {
                    $request->result = $this->fulfil($organizationId, $actor, $request, $data);
                }
            } else {
                $request->manager_decision_basis = $data['basis'];
                $request->manager_decided_by = $actor->id;
            }
            $request->status = $status;
            $request->revision++;
            $request->save();
            $this->workspace->notify($request);

            return $this->workspace->present($request);
        });
    }

    private function fulfil(int $organizationId, User $actor, EmployeeRequest $request, array $data): array
    {
        $basisReference = trim((string) ($data['basis_reference'] ?? ''));
        if ($basisReference === '') {
            throw new DomainException(trans_message('workforce.errors.request_invalid'));
        }
        $employee = WorkforceEmployee::query()->where('organization_id', $organizationId)->findOrFail($request->employee_id);
        if ($employee->employment_status !== 'active') {
            throw new DomainException(trans_message('workforce.errors.employee_not_active'));
        }
        if (! empty($data['basis_document_id'])) {
            $document = HrDocument::query()->where('organization_id', $organizationId)->where('employee_id', $request->employee_id)
                ->find((int) $data['basis_document_id']);
            if ($document === null) {
                throw new DomainException(trans_message('workforce.errors.hr_file_invalid'));
            }
            $file = app(HrDocumentService::class)->file($organizationId, (int) $document->file_id);
            if ((int) ($file->additional_info['hr_employee_id'] ?? 0) !== (int) $request->employee_id
                || $file->fileable_type !== WorkforceEmployee::class || (int) $file->fileable_id !== (int) $request->employee_id) {
                throw new DomainException(trans_message('workforce.errors.hr_file_invalid'));
            }
        }
        if ($request->kind === 'absence') {
            $service = app(WorkforceProService::class);
            $absence = $service->storeAbsence($organizationId, ['employee_id' => $request->employee_id,
                'absence_type_code' => $request->requested_data['absence_type_code'], 'start_date' => $request->date_from,
                'end_date' => $request->date_to, 'reason_code' => $request->requested_data['absence_type_code'],
                'basis_reference' => $basisReference, 'basis_document_id' => $data['basis_document_id'] ?? null, 'comment' => $data['basis']], $actor);
            $service->approveAbsence($organizationId, (int) $absence['id'], $actor);

            return ['type' => 'absence', 'id' => $absence['id']];
        }
        $correction = app(WorkforceAttendanceService::class)->storeCorrection($organizationId, (int) $request->employee_id, (int) $actor->id,
            ['work_date' => $request->date_from, 'status' => $request->requested_data['status'],
                'hours' => $request->requested_data['hours'] ?? null, 'project_id' => $request->requested_data['project_id'] ?? null,
                'reason' => mb_substr($data['basis'].' / '.$basisReference, 0, 500)]);

        return ['type' => 'attendance_correction', 'id' => $correction['id']];
    }

    public function scopes(int $organizationId, ?User $actor): array
    {
        $this->access->assert($organizationId, $actor);

        return DB::table('workforce_team_scopes')->where('organization_id', $organizationId)->orderBy('id')->limit(500)->get()->map(fn ($row) => (array) $row)->all();
    }

    public function storeScope(int $organizationId, ?User $actor, array $payload): array
    {
        $this->access->assert($organizationId, $actor);
        $data = Validator::make($payload, ['manager_user_id' => ['required', 'integer'], 'employee_id' => ['required', 'integer'],
            'valid_from' => ['required', 'date_format:Y-m-d'], 'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from']])->validate();
        $employee = WorkforceEmployee::query()->where('organization_id', $organizationId)->findOrFail($data['employee_id']);
        $manager = User::query()->find($data['manager_user_id']);
        if (! $this->access->allows($organizationId, $manager, 'workforce.team-queue.manage') || (int) $employee->user_id === (int) $data['manager_user_id']) {
            throw new AuthorizationException(trans_message('workforce.errors.team_scope_denied'));
        }
        DB::table('workforce_team_scopes')->updateOrInsert(['organization_id' => $organizationId, 'manager_user_id' => $data['manager_user_id'], 'employee_id' => $data['employee_id']],
            ['valid_from' => $data['valid_from'], 'valid_to' => $data['valid_to'] ?? null, 'recorded_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);

        return (array) DB::table('workforce_team_scopes')->where('organization_id', $organizationId)->where('manager_user_id', $data['manager_user_id'])->where('employee_id', $data['employee_id'])->first();
    }
}
