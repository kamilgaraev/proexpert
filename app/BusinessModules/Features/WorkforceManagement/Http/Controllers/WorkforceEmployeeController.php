<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Http\Controllers;

use App\BusinessModules\Features\WorkforceManagement\Http\Resources\WorkforceEmployeeCardResource;
use App\BusinessModules\Features\WorkforceManagement\Http\Resources\WorkforceEmployeeResource;
use App\BusinessModules\Features\WorkforceManagement\Services\WorkforceEmployeeService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class WorkforceEmployeeController extends Controller
{
    public function __construct(private readonly WorkforceEmployeeService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            return $this->paginated($this->service->paginate(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                $request->only(['status', 'search'])
            ));
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'employees.index');
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validate($this->rules($organizationId));

            return AdminResponse::success(
                new WorkforceEmployeeResource($this->service->create($organizationId, $validated)),
                trans_message('workforce.messages.employee_created'),
                201
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'employees.store');
        }
    }

    public function show(Request $request, int $employeeId): JsonResponse
    {
        try {
            return AdminResponse::success(new WorkforceEmployeeResource($this->service->find(
                (int) $request->attributes->get('current_organization_id'),
                $employeeId
            )));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 404);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'employees.show');
        }
    }

    public function card(Request $request, int $employeeId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'work_date' => ['nullable', 'date'],
            ]);

            return AdminResponse::success(new WorkforceEmployeeCardResource($this->service->card(
                (int) $request->attributes->get('current_organization_id'),
                $employeeId,
                $validated['work_date'] ?? null
            )));
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 404);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'employees.card');
        }
    }

    public function update(Request $request, int $employeeId): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validate($this->rules($organizationId, $employeeId, partial: true));

            return AdminResponse::success(
                new WorkforceEmployeeResource($this->service->update($organizationId, $employeeId, $validated)),
                trans_message('workforce.messages.employee_updated')
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'employees.update');
        }
    }

    public function dismiss(Request $request, int $employeeId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'dismissal_date' => ['nullable', 'date'],
            ]);

            return AdminResponse::success(
                new WorkforceEmployeeResource($this->service->dismiss(
                    (int) $request->attributes->get('current_organization_id'),
                    $employeeId,
                    $validated['dismissal_date'] ?? null
                )),
                trans_message('workforce.messages.employee_dismissed')
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'employees.dismiss');
        }
    }

    private function paginated(LengthAwarePaginator $paginator): JsonResponse
    {
        return AdminResponse::paginated(WorkforceEmployeeResource::collection($paginator->items()), [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ]);
    }

    private function rules(int $organizationId, ?int $employeeId = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'user_id' => ['nullable', 'integer'],
            'personnel_number' => [
                $required,
                'string',
                'max:80',
                Rule::unique('workforce_employees', 'personnel_number')
                    ->where('organization_id', $organizationId)
                    ->ignore($employeeId),
            ],
            'last_name' => [$required, 'string', 'max:255'],
            'first_name' => [$required, 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'employment_status' => [$partial ? 'sometimes' : 'nullable', Rule::in(['active', 'dismissed', 'inactive'])],
            'hire_date' => [$required, 'date'],
            'dismissal_date' => ['nullable', 'date', 'after_or_equal:hire_date'],
            'external_payroll_ref' => [
                'nullable',
                'string',
                'max:120',
                Rule::unique('workforce_employees', 'external_payroll_ref')
                    ->where('organization_id', $organizationId)
                    ->ignore($employeeId),
            ],
            'phone' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    private function failed(Request $request, \Throwable $exception, string $action): JsonResponse
    {
        Log::error('workforce.employees_failed', [
            'action' => $action,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'user_id' => $request->user()?->id,
            'error' => $exception->getMessage(),
        ]);

        return AdminResponse::error(trans_message('workforce.errors.unexpected'), 500);
    }
}
