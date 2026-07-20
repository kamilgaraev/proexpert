<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Resources\Api\V1\Customer\CustomerLegalArchiveDocumentResource;
use App\Http\Responses\CustomerResponse;
use App\Models\Contract;
use App\Services\Customer\CustomerLegalArchiveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

use function trans_message;

final class LegalArchiveController extends CustomerController
{
    public function __construct(private readonly CustomerLegalArchiveService $archive) {}

    public function index(Request $request): JsonResponse
    {
        try {
            return CustomerResponse::success(CustomerLegalArchiveDocumentResource::collection(
                $this->archive->documents($request->user(), $this->resolveOrganizationId($request))
            ), trans_message('customer.documents_loaded'));
        } catch (Throwable $error) {
            return $this->failure($request, $error, 'index');
        }
    }

    public function contract(Request $request, Contract $contract): JsonResponse
    {
        try {
            return CustomerResponse::success(CustomerLegalArchiveDocumentResource::collection(
                $this->archive->documents($request->user(), $this->resolveOrganizationId($request), $contract)
            ), trans_message('customer.documents_loaded'));
        } catch (Throwable $error) {
            return $this->failure($request, $error, 'contract', ['contract_id' => $contract->id]);
        }
    }

    public function show(Request $request, int $document): JsonResponse
    {
        try {
            $found = $this->archive->document($request->user(), $this->resolveOrganizationId($request), $document);
            if ($found === null) {
                return CustomerResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
            }
            return CustomerResponse::success(new CustomerLegalArchiveDocumentResource($found), trans_message('customer.documents_loaded'));
        } catch (Throwable $error) {
            return $this->failure($request, $error, 'show', ['document_id' => $document]);
        }
    }

    public function fileUrl(Request $request, int $version, string $purpose): JsonResponse
    {
        try {
            $url = $this->archive->url($request->user(), $this->resolveOrganizationId($request), $version, $purpose);
            return $url === null
                ? CustomerResponse::error(trans_message('legal_archive.messages.document_not_found'), 404)
                : CustomerResponse::success($url, trans_message('legal_archive.messages.file_url_created'));
        } catch (Throwable $error) {
            return $this->failure($request, $error, 'file_url', ['version_id' => $version]);
        }
    }

    public function decide(Request $request, int $step, string $action): JsonResponse
    {
        try {
            $validated = Validator::make($request->all(), [
                'idempotency_key' => ['required', 'uuid'],
                'instance_lock_version' => ['required', 'integer', 'min:0'],
                'step_lock_version' => ['required', 'integer', 'min:0'],
                'comment' => ['nullable', 'string', 'max:5000'],
                'reason' => ['nullable', 'string', 'max:5000'],
            ])->validate();
            if (in_array($action, ['reject', 'return'], true) && blank($validated['comment'] ?? null)) {
                return CustomerResponse::error(trans_message('legal_archive.messages.validation_error'), 422, ['comment' => ['required']]);
            }
            $this->archive->decide($request->user(), $this->resolveOrganizationId($request), $step, $action, $validated);
            return CustomerResponse::success(null, trans_message('legal_archive.messages.workflow_decided'));
        } catch (Throwable $error) {
            return $this->failure($request, $error, 'workflow_'.$action, ['step_id' => $step]);
        }
    }

    private function failure(Request $request, Throwable $error, string $operation, array $context = []): JsonResponse
    {
        if ($error instanceof \Illuminate\Auth\Access\AuthorizationException) {
            return CustomerResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
        }
        if ($error instanceof \Illuminate\Validation\ValidationException) {
            return CustomerResponse::error(trans_message('legal_archive.messages.validation_error'), 422, $error->errors());
        }
        Log::error('customer.legal_archive.'.$operation.'_failed', ['user_id' => $request->user()?->id, ...$context, 'error' => $error::class]);
        return CustomerResponse::error(trans_message('legal_archive.messages.operation_failed'), 500);
    }
}
