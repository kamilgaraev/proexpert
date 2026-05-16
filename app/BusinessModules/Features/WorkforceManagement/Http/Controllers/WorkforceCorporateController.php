<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Http\Controllers;

use App\BusinessModules\Features\WorkforceManagement\Services\WorkforceCorporateService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class WorkforceCorporateController extends Controller
{
    public function __construct(private readonly WorkforceCorporateService $service)
    {
    }

    public function accountingMappings(Request $request): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->listAccountingMappings($this->organizationId($request)));
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'accounting_mappings.index');
        }
    }

    public function storeAccountingMapping(Request $request): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->storeAccountingMapping($this->organizationId($request), $request->validate($this->accountingMappingRules())), trans_message('workforce.messages.record_created'), 201);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'accounting_mappings.store');
        }
    }

    public function updateAccountingMapping(Request $request, int $mappingId): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->updateAccountingMapping($this->organizationId($request), $mappingId, $request->validate($this->accountingMappingRules(partial: true))), trans_message('workforce.messages.record_updated'));
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'accounting_mappings.update');
        }
    }

    public function lockPayrollPeriod(Request $request, int $periodId): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->lockPayrollPeriod($this->organizationId($request), $periodId, (int) $request->user()?->id), trans_message('workforce.messages.payroll_period_locked'));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'payroll_periods.lock');
        }
    }

    public function exportPackages(Request $request): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->exportPackages($this->organizationId($request)));
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'export_packages.index');
        }
    }

    public function showExportPackage(Request $request, int $packageId): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->showExportPackage($this->organizationId($request), $packageId));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 404);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'export_packages.show');
        }
    }

    public function createExportPackage(Request $request, int $periodId): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->createExportPackage($this->organizationId($request), $periodId, (int) $request->user()?->id), trans_message('workforce.messages.export_package_created'), 201);
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'export_packages.store');
        }
    }

    public function downloadExportFile(Request $request, int $packageId, int $fileId): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->exportPackageFile($this->organizationId($request), $packageId, $fileId));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 404);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'export_files.download');
        }
    }

    public function markSent(Request $request, int $packageId): JsonResponse
    {
        return $this->mark($request, $packageId, 'sent');
    }

    public function markAccepted(Request $request, int $packageId): JsonResponse
    {
        return $this->mark($request, $packageId, 'accepted');
    }

    public function markRejected(Request $request, int $packageId): JsonResponse
    {
        try {
            $payload = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

            return AdminResponse::success($this->service->markExportPackage($this->organizationId($request), $packageId, 'rejected', $payload['reason'] ?? null), trans_message('workforce.messages.record_updated'));
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'export_packages.reject');
        }
    }

    private function mark(Request $request, int $packageId, string $status): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->markExportPackage($this->organizationId($request), $packageId, $status), trans_message('workforce.messages.record_updated'));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, "export_packages.{$status}");
        }
    }

    private function accountingMappingRules(bool $partial = false): array
    {
        return [
            'scope_type' => [$partial ? 'sometimes' : 'required', Rule::in(['organization', 'project', 'department', 'staff_unit'])],
            'scope_id' => ['nullable', 'integer'],
            'cost_category_id' => ['nullable', 'integer'],
            'accounting_account' => [$partial ? 'sometimes' : 'required', 'string', 'max:80'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    private function organizationId(Request $request): int
    {
        return (int) $request->attributes->get('current_organization_id');
    }

    private function failed(Request $request, \Throwable $exception, string $action): JsonResponse
    {
        Log::error('workforce.corporate_failed', [
            'action' => $action,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'user_id' => $request->user()?->id,
            'error' => $exception->getMessage(),
        ]);

        return AdminResponse::error(trans_message('workforce.errors.unexpected'), 500);
    }
}
