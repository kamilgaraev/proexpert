<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\BusinessModules\Features\WorkforceManagement\Http\Resources\WorkforceEmployeeResource;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\BusinessModules\Features\WorkforceManagement\Services\WorkforceEmployeeService;
use App\BusinessModules\Features\WorkforceManagement\Services\WorkforceAttendanceService;
use App\BusinessModules\Features\WorkforceManagement\Services\WorkforceProService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Services\Project\ProjectTeamService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class MobileFieldAdminService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly MobileProjectAccessResolver $projectAccess,
        private readonly ProjectTeamService $teams,
        private readonly WorkforceEmployeeService $employees,
        private readonly WorkforceProService $workforce,
        private readonly WorkforceAttendanceService $attendanceService,
    ) {}

    public function projectTeam(User $actor, int $organizationId, int $projectId, array $filters): array
    {
        $this->authorize($actor, 'projects.view', $organizationId, $projectId);
        $project = $this->accessibleProject($actor, $organizationId, $projectId);
        $query = $project->users()
            ->wherePivot('is_active', true)
            ->when(isset($filters['q']) && $filters['q'] !== '', static function ($query) use ($filters): void {
                $search = (string) $filters['q'];
                $query->where(static function ($memberQuery) use ($search): void {
                    $memberQuery->where('users.name', 'like', '%' . $search . '%')
                        ->orWhere('users.email', 'like', '%' . $search . '%');
                });
            })
            ->orderBy('users.name')
            ->orderBy('users.id');
        $page = $query->paginate(min(max((int) ($filters['per_page'] ?? 20), 1), 100));

        return $this->page($page, static fn (User $member): array => [
            'id' => (int) $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'project_role' => $member->pivot?->role,
        ]);
    }

    public function availableProjectUsers(User $actor, int $organizationId, int $projectId, array $filters): array
    {
        $this->authorize($actor, 'projects.participants.assign', $organizationId, $projectId);
        $project = $this->accessibleProject($actor, $organizationId, $projectId);
        $page = $this->teams->paginateAvailableMembers(
            $project,
            $organizationId,
            ['search' => $filters['q'] ?? null],
            min(max((int) ($filters['per_page'] ?? 20), 1), 100)
        );

        return $this->page($page, static fn (User $member): array => [
            'id' => (int) $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'already_assigned' => $member->assignedProjects->contains('id', $projectId),
        ]);
    }
    public function bindProjectUser(User $actor, int $organizationId, int $projectId, int $userId): array
    {
        $this->authorize($actor, 'projects.participants.assign', $organizationId, $projectId);
        $project = $this->accessibleProject($actor, $organizationId, $projectId);
        $member = User::query()->find($userId);
        if (! $member instanceof User) {
            throw (new ModelNotFoundException())->setModel(User::class, [$userId]);
        }

        $this->teams->assignMember($project, $member, $actor, $organizationId);

        return ['project_id' => $projectId, 'user_id' => $userId, 'active' => true];
    }

    public function employees(User $actor, int $organizationId, array $filters): array
    {
        $projectId = isset($filters['project_id']) ? (int) $filters['project_id'] : null;
        $this->authorize($actor, 'workforce.view', $organizationId, $projectId);
        if ($projectId !== null) {
            $this->accessibleProject($actor, $organizationId, $projectId);
        }
        $page = $this->employees->paginate(
            $organizationId,
            min(max((int) ($filters['per_page'] ?? 20), 1), 100),
            ['search' => $filters['q'] ?? null, 'project_id' => $projectId]
        );

        return $this->page($page, static fn ($employee): array => (new WorkforceEmployeeResource($employee))->resolve());
    }

    public function employee(User $actor, int $organizationId, int $employeeId, array $filters = []): array
    {
        $projectId = isset($filters['project_id']) ? (int) $filters['project_id'] : null;
        $this->authorize($actor, 'workforce.view', $organizationId, $projectId);
        if ($projectId !== null) {
            $this->accessibleProject($actor, $organizationId, $projectId);
            $today = now()->toDateString();
            $assigned = DB::table('workforce_employee_assignments')
                ->where('organization_id', $organizationId)
                ->where('employee_id', $employeeId)
                ->where('project_id', $projectId)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->whereDate('valid_from', '<=', $today)
                ->where(static function ($dates) use ($today): void {
                    $dates->whereNull('valid_to')->orWhereDate('valid_to', '>=', $today);
                })
                ->exists();
            if (! $assigned) {
                throw (new ModelNotFoundException())->setModel('workforce_employee', [$employeeId]);
            }
        }
        try {
            $employee = $this->employees->find($organizationId, $employeeId);
        } catch (DomainException) {
            throw (new ModelNotFoundException())->setModel('workforce_employee', [$employeeId]);
        }

        return ['item' => (new WorkforceEmployeeResource($employee))->resolve()];
    }

    public function absences(User $actor, int $organizationId, array $filters): array
    {
        $projectId = isset($filters['project_id']) ? (int) $filters['project_id'] : null;
        $this->authorize($actor, 'workforce.view', $organizationId, $projectId);
        if ($projectId !== null) {
            $this->accessibleProject($actor, $organizationId, $projectId);
        }
        $page = $this->workforce->paginateList(
            'workforce_absences',
            $organizationId,
            min(max((int) ($filters['per_page'] ?? 20), 1), 100),
            $filters['q'] ?? null,
            $projectId
        );

        return $this->page($page, static fn (array $item): array => $item);
    }

    public function orders(User $actor, int $organizationId, array $filters): array
    {
        $projectId = isset($filters['project_id']) ? (int) $filters['project_id'] : null;
        $this->authorize($actor, 'workforce.view', $organizationId, $projectId);
        if ($projectId !== null) {
            $this->accessibleProject($actor, $organizationId, $projectId);
        }
        $page = $this->workforce->paginateList(
            'workforce_orders',
            $organizationId,
            min(max((int) ($filters['per_page'] ?? 20), 1), 100),
            null,
            $projectId
        );

        return $this->page($page, static fn (array $item): array => $item);
    }

    public function attendance(User $actor, int $organizationId, array $filters): array
    {
        $projectId = (int) $filters['project_id'];
        $this->authorize($actor, 'workforce.view', $organizationId, $projectId);
        $this->accessibleProject($actor, $organizationId, $projectId);

        $date = (string) $filters['work_date'];
        $sheet = $this->attendanceService->sheet($organizationId, $date, $date, $projectId);
        $rows = $sheet['rows'];
        $page = (int) ($filters['page'] ?? 1);
        $perPage = min((int) ($filters['per_page'] ?? 20), 50);
        $total = count($rows);

        return [
            'items' => array_map(static function (array $row) use ($date): array {
                $day = $row['days'][$date] ?? [];

                return [
                    'employee_id' => $row['employee_id'],
                    'employee_label' => $row['employee_label'],
                    'assignment_label' => $row['assignment_label'],
                    'project_id' => $row['project_id'],
                    'work_date' => $date,
                    'status' => $day['status'] ?? null,
                    'status_label' => $day['status_label'] ?? null,
                    'source' => $day['source'] ?? null,
                    'source_label' => $day['source_label'] ?? null,
                    'hours' => $day['hours'] ?? null,
                ];
            }, array_slice($rows, ($page - 1) * $perPage, $perPage)),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    public function calendar(User $actor, int $organizationId, array $filters): array
    {
        $projectId = (int) $filters['project_id'];
        $this->authorize($actor, 'workforce.view', $organizationId, $projectId);
        $this->accessibleProject($actor, $organizationId, $projectId);

        return $this->workforce->scheduleCalendar(
            $organizationId,
            (string) $filters['date_from'],
            (string) $filters['date_to'],
            $projectId
        );
    }

    private function accessibleProject(User $actor, int $organizationId, int $projectId): Project
    {
        try {
            return $this->projectAccess->resolve($actor, $organizationId, $projectId, trans_message('workforce.errors.project_not_found'));
        } catch (DomainException) {
            throw (new ModelNotFoundException())->setModel(Project::class, [$projectId]);
        }
    }

    private function authorize(User $actor, string $permission, int $organizationId, ?int $projectId = null): void
    {
        $context = ['organization_id' => $organizationId];
        if ($projectId !== null) {
            $context['project_id'] = $projectId;
            $context['strict_project_scope'] = true;
        }

        if (! $this->authorization->can($actor, $permission, $context)) {
            throw new AuthorizationException(trans_message('workforce.errors.permission_denied'));
        }
    }

    private function page(LengthAwarePaginator $page, callable $map): array
    {
        return [
            'items' => array_map($map, $page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }
}
