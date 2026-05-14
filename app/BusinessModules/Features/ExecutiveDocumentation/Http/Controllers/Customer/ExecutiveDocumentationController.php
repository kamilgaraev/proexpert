<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Controllers\Customer;

use App\BusinessModules\Features\ExecutiveDocumentation\Http\Resources\ExecutiveDocumentRemarkResource;
use App\BusinessModules\Features\ExecutiveDocumentation\Http\Resources\ExecutiveDocumentSetResource;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentationService;
use App\Http\Controllers\Api\V1\Customer\CustomerController;
use App\Http\Responses\CustomerResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ExecutiveDocumentationController extends CustomerController
{
    public function __construct(
        private readonly ExecutiveDocumentationService $service,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);

            if (!$this->hasPermission($request, 'executive-documentation.view', $organizationId)) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 403);
            }

            return CustomerResponse::success(
                ExecutiveDocumentSetResource::collection(
                    $this->service->listSets($organizationId, $request->only(['project_id']), true)
                )->resolve()
            );
        } catch (\Throwable $e) {
            Log::error('executive_documentation.customer.index.error', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('executive_documentation.errors.index_failed'), 500);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);

            if (!$this->hasPermission($request, 'executive-documentation.view', $organizationId)) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 403);
            }

            $set = $this->service->findSet($id, $organizationId, true);

            if ($set === null) {
                return CustomerResponse::error(trans_message('executive_documentation.errors.not_found'), 404);
            }

            return CustomerResponse::success(new ExecutiveDocumentSetResource($set));
        } catch (\Throwable $e) {
            Log::error('executive_documentation.customer.show.error', [
                'id' => $id,
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('executive_documentation.errors.show_failed'), 500);
        }
    }

    public function storeRemark(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);

            if (!$this->hasPermission($request, 'executive-documentation.review', $organizationId)) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 403);
            }

            $validated = $request->validate([
                'body' => ['required', 'string', 'max:5000'],
                'severity' => ['nullable', 'string', Rule::in(['minor', 'major', 'critical'])],
            ]);
            $document = $this->service->findDocument($id, $organizationId);

            if ($document === null) {
                return CustomerResponse::error(trans_message('executive_documentation.errors.document_not_found'), 404);
            }

            return CustomerResponse::success(
                new ExecutiveDocumentRemarkResource($this->service->addCustomerRemark($document, (int) $request->user()?->id, $validated)),
                trans_message('executive_documentation.messages.remark_created'),
                201
            );
        } catch (ValidationException $e) {
            return CustomerResponse::error($e->getMessage(), 422, $e->errors());
        } catch (DomainException $e) {
            return CustomerResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::error('executive_documentation.customer.remark.error', [
                'document_id' => $id,
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('executive_documentation.errors.remark_failed'), 500);
        }
    }

    public function acknowledge(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);

            if (!$this->hasPermission($request, 'executive-documentation.approve', $organizationId)) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 403);
            }

            $validated = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);
            $set = $this->service->findSet($id, $organizationId, true);

            if ($set === null) {
                return CustomerResponse::error(trans_message('executive_documentation.errors.not_found'), 404);
            }

            return CustomerResponse::success(new ExecutiveDocumentSetResource(
                $this->service->acknowledgeTransmittal($set, (int) $request->user()?->id, $validated['comment'] ?? null)
            ));
        } catch (ValidationException $e) {
            return CustomerResponse::error($e->getMessage(), 422, $e->errors());
        } catch (DomainException $e) {
            return CustomerResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::error('executive_documentation.customer.acknowledge.error', [
                'set_id' => $id,
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('executive_documentation.errors.acknowledge_failed'), 500);
        }
    }
}
