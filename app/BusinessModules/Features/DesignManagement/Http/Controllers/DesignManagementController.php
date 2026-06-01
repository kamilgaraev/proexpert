<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Controllers;

use App\BusinessModules\Features\DesignManagement\DesignManagementModule;
use App\BusinessModules\Features\DesignManagement\Enums\DesignPackageStatusEnum;
use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignArtifactVersionResource;
use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignModelDerivativeResource;
use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignPackageResource;
use App\BusinessModules\Features\DesignManagement\Services\DesignManagementService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class DesignManagementController extends Controller
{
    public function __construct(
        private readonly DesignManagementService $service,
        private readonly DesignManagementModule $module,
    ) {
    }

    public function packages(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'project_id' => ['nullable', 'integer'],
                'status' => ['nullable', 'string', Rule::in($this->packageStatuses())],
                'discipline' => ['nullable', 'string', 'max:120'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
                'page' => ['nullable', 'integer', 'min:1'],
            ]);
            $paginator = $this->service->listPackages($this->organizationId($request), $validated);

            return AdminResponse::paginated(
                DesignPackageResource::collection($paginator->getCollection()),
                $this->paginationMeta($paginator),
                trans_message('design_management.messages.packages_loaded'),
                links: $this->paginationLinks($paginator)
            );
        } catch (ValidationException $e) {
            return $this->validationFailed($e);
        } catch (\Throwable $e) {
            return $this->failed('index', null, $e);
        }
    }

    public function storePackage(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'project_id' => ['required', 'integer'],
                'title' => ['required', 'string', 'max:255'],
                'stage' => ['nullable', 'string', 'max:120'],
                'discipline' => ['nullable', 'string', 'max:120'],
                'status' => ['nullable', 'string', Rule::in($this->packageStatuses())],
                'planned_issue_date' => ['nullable', 'date'],
                'metadata' => ['nullable', 'array'],
            ]);

            return AdminResponse::success(
                new DesignPackageResource($this->service->createPackage($this->organizationId($request), (int) auth()->id(), $validated)),
                trans_message('design_management.messages.package_created'),
                201
            );
        } catch (ValidationException $e) {
            return $this->validationFailed($e);
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failed('store_package', null, $e);
        }
    }

    public function showPackage(Request $request, int $packageId): JsonResponse
    {
        try {
            $package = $this->service->findPackage($this->organizationId($request), $packageId);

            if ($package === null) {
                return AdminResponse::error(trans_message('design_management.errors.package_not_found'), 404);
            }

            return AdminResponse::success(
                new DesignPackageResource($package),
                trans_message('design_management.messages.package_loaded')
            );
        } catch (\Throwable $e) {
            return $this->failed('show_package', $packageId, $e);
        }
    }

    public function uploadModel(Request $request, int $packageId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'file' => ['required', 'file', 'max:' . $this->maxIfcSizeKilobytes(), $this->extensionRule('ifc')],
                'title' => ['required', 'string', 'max:255'],
                'version_number' => ['required', 'string', 'max:80'],
                'revision' => ['nullable', 'string', 'max:80'],
                'discipline' => ['nullable', 'string', 'max:120'],
                'stage' => ['nullable', 'string', 'max:120'],
                'model_date' => ['nullable', 'date'],
                'make_current' => ['nullable', 'boolean'],
                'metadata' => ['nullable', 'array'],
                'artifact_metadata' => ['nullable', 'array'],
            ]);
            $package = $this->service->findPackage($this->organizationId($request), $packageId);

            if ($package === null) {
                return AdminResponse::error(trans_message('design_management.errors.package_not_found'), 404);
            }

            return AdminResponse::success(
                new DesignArtifactVersionResource($this->service->uploadIfcModel(
                    $package,
                    (int) auth()->id(),
                    $validated['file'],
                    $validated
                )),
                trans_message('design_management.messages.model_uploaded'),
                201
            );
        } catch (ValidationException $e) {
            return $this->validationFailed($e);
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failed('upload_model', $packageId, $e);
        }
    }

    public function viewerPayload(Request $request, int $versionId): JsonResponse
    {
        try {
            $version = $this->service->findVersion($this->organizationId($request), $versionId);

            if ($version === null) {
                return AdminResponse::error(trans_message('design_management.errors.version_not_found'), 404);
            }

            return AdminResponse::success(
                $this->service->viewerPayload($version),
                trans_message('design_management.messages.viewer_payload_loaded')
            );
        } catch (\Throwable $e) {
            return $this->failed('viewer_payload', $versionId, $e);
        }
    }

    public function storeDerivative(Request $request, int $versionId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'file' => ['required', 'file', 'max:' . $this->maxDerivativeSizeKilobytes(), $this->extensionRule('frag')],
                'viewer_provider' => ['nullable', 'string', Rule::in(['thatopen'])],
                'derivative_format' => ['nullable', 'string', Rule::in(['thatopen_frag'])],
                'metadata' => ['nullable', 'array'],
            ]);
            $version = $this->service->findVersion($this->organizationId($request), $versionId);

            if ($version === null) {
                return AdminResponse::error(trans_message('design_management.errors.version_not_found'), 404);
            }

            return AdminResponse::success(
                new DesignModelDerivativeResource($this->service->storeDerivative(
                    $version,
                    (int) auth()->id(),
                    $validated['file'],
                    $validated
                )),
                trans_message('design_management.messages.derivative_uploaded'),
                201
            );
        } catch (ValidationException $e) {
            return $this->validationFailed($e);
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failed('store_derivative', $versionId, $e);
        }
    }

    public function markCurrent(Request $request, int $versionId): JsonResponse
    {
        try {
            $version = $this->service->findVersion($this->organizationId($request), $versionId);

            if ($version === null) {
                return AdminResponse::error(trans_message('design_management.errors.version_not_found'), 404);
            }

            return AdminResponse::success(
                new DesignArtifactVersionResource($this->service->markCurrent($version, (int) auth()->id())),
                trans_message('design_management.messages.version_marked_current')
            );
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failed('mark_current', $versionId, $e);
        }
    }

    private function organizationId(Request $request): int
    {
        return (int) $request->attributes->get('current_organization_id');
    }

    private function extensionRule(string $extension): callable
    {
        return static function (string $attribute, mixed $value, \Closure $fail) use ($extension): void {
            if (!$value instanceof UploadedFile || strtolower($value->getClientOriginalExtension()) !== $extension) {
                $fail(trans_message("design_management.errors.{$extension}_file_required"));
            }
        };
    }

    private function maxIfcSizeKilobytes(): int
    {
        $limits = $this->module->getLimits();

        return max(1, (int) ($limits['max_ifc_file_size_mb'] ?? 500)) * 1024;
    }

    private function maxDerivativeSizeKilobytes(): int
    {
        return $this->maxIfcSizeKilobytes();
    }

    private function packageStatuses(): array
    {
        return array_map(static fn (DesignPackageStatusEnum $status): string => $status->value, DesignPackageStatusEnum::cases());
    }

    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'from' => $paginator->firstItem(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'to' => $paginator->lastItem(),
            'total' => $paginator->total(),
        ];
    }

    private function paginationLinks(LengthAwarePaginator $paginator): array
    {
        return [
            'first' => $paginator->url(1),
            'last' => $paginator->url($paginator->lastPage()),
            'prev' => $paginator->previousPageUrl(),
            'next' => $paginator->nextPageUrl(),
        ];
    }

    private function validationFailed(ValidationException $e): JsonResponse
    {
        return AdminResponse::error(trans_message('design_management.errors.validation_failed'), 422, $e->errors());
    }

    private function failed(string $action, ?int $id, \Throwable $e): JsonResponse
    {
        Log::error("design_management.{$action}.error", [
            'action' => $action,
            'id' => $id,
            'user_id' => auth()->id(),
            'organization_id' => request()?->attributes->get('current_organization_id'),
            'error' => $e->getMessage(),
        ]);

        return AdminResponse::error(trans_message("design_management.errors.{$action}_failed"), 500);
    }
}
