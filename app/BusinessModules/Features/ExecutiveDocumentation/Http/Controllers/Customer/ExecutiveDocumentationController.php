<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Controllers\Customer;

use App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests\CustomerTransmittalDecisionRequest;
use App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests\CustomerTransmittalRemarkRequest;
use App\BusinessModules\Features\ExecutiveDocumentation\Http\Resources\ExecutiveDocumentRemarkResource;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveTransmittalService;
use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Api\V1\Customer\CustomerController;
use App\Http\Responses\CustomerResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class ExecutiveDocumentationController extends CustomerController
{
    public function __construct(private readonly ExecutiveTransmittalService $transmittals) {}

    public function index(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $userId = (int) $request->user()?->id;
            return $this->transmittals->list($userId, $request->filled('project_id') ? (int) $request->input('project_id') : null)
                ->map(fn ($item) => $this->transmittals->present($item, $userId))->all();
        });
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->respond(fn () => $this->transmittals->present(
            $this->transmittals->latestForSet($id, (int) $request->user()?->id), (int) $request->user()?->id
        ));
    }

    public function download(Request $request, int $id, int $versionId): JsonResponse
    {
        return $this->respond(fn () => ['url' => $this->transmittals->download($id, $versionId, (int) $request->user()?->id)]);
    }

    public function decision(CustomerTransmittalDecisionRequest $request, int $id, string $action): JsonResponse
    {
        return $this->respond(fn () => $this->transmittals->present(
            $this->transmittals->decide($id, (int) $request->user()?->id, $action, $request->validated()), (int) $request->user()?->id
        ));
    }

    public function storeRemark(CustomerTransmittalRemarkRequest $request, int $id): JsonResponse
    {
        return $this->respond(fn () => new ExecutiveDocumentRemarkResource(
            $this->transmittals->remark($id, (int) $request->user()?->id, $request->validated())
        ));
    }

    public function acknowledge(Request $request, int $id): JsonResponse
    {
        return CustomerResponse::error(trans_message('executive_documentation.errors.transmittal_client_upgrade'), 409);
    }

    private function respond(callable $action): JsonResponse
    {
        try {
            return CustomerResponse::success($action());
        } catch (BusinessLogicException $exception) {
            return CustomerResponse::error($exception->getMessage(), in_array($exception->getCode(), [403, 404], true) ? $exception->getCode() : 422);
        } catch (DomainException $exception) {
            return CustomerResponse::error($exception->getMessage(), 409);
        } catch (\Throwable $exception) {
            Log::error('executive_documentation.customer.failed', ['exception' => $exception::class]);
            return CustomerResponse::error(trans_message('executive_documentation.errors.show_failed'), 500);
        }
    }
}
