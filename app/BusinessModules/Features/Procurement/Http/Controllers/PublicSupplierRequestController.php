<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Controllers;

use App\BusinessModules\Features\Procurement\Enums\SupplierProposalIntakeSourceEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Http\Requests\StorePublicSupplierProposalRequest;
use App\BusinessModules\Features\Procurement\Http\Resources\PublicSupplierRequestResource;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\Procurement\Services\ProcurementLifecycleService;
use App\BusinessModules\Features\Procurement\Services\SupplierProposalService;
use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function trans_message;

class PublicSupplierRequestController extends Controller
{
    public function __construct(
        private readonly SupplierProposalService $proposalService,
        private readonly ProcurementLifecycleService $lifecycleService
    ) {
    }

    public function show(Request $request, string $token): JsonResponse
    {
        try {
            $supplierRequest = $this->findByToken($token);

            if (!$supplierRequest instanceof SupplierRequest) {
                return LandingResponse::error(trans_message('procurement.public_supplier_requests.not_found'), 404);
            }

            $supplierRequest = $this->lifecycleService->syncSupplierRequestExpiry($supplierRequest);
            $blockedResponse = $this->blockedResponse($supplierRequest, false);

            if ($blockedResponse instanceof JsonResponse) {
                return $blockedResponse;
            }

            if ($supplierRequest->status === SupplierRequestStatusEnum::SENT && $supplierRequest->public_opened_at === null) {
                $supplierRequest->update(['public_opened_at' => now()]);
                $supplierRequest->refresh();
            }

            return LandingResponse::success(new PublicSupplierRequestResource($supplierRequest));
        } catch (Throwable $exception) {
            Log::error('procurement.public_supplier_requests.show.error', [
                'token_hash' => hash('sha256', $token),
                'exception' => $exception->getMessage(),
            ]);

            return LandingResponse::error(trans_message('procurement.public_supplier_requests.show_error'), 500);
        }
    }

    public function submit(StorePublicSupplierProposalRequest $request, string $token): JsonResponse
    {
        try {
            $supplierRequest = $this->findByToken($token);

            if (!$supplierRequest instanceof SupplierRequest) {
                return LandingResponse::error(trans_message('procurement.public_supplier_requests.not_found'), 404);
            }

            $supplierRequest = $this->lifecycleService->syncSupplierRequestExpiry($supplierRequest);
            $blockedResponse = $this->blockedResponse($supplierRequest, true);

            if ($blockedResponse instanceof JsonResponse) {
                return $blockedResponse;
            }

            $data = $request->validated();
            $data['intake'] = [
                'source' => SupplierProposalIntakeSourceEnum::OTHER->value,
                'received_at' => now()->toIso8601String(),
                'comment' => trans_message('procurement.public_supplier_requests.intake_comment'),
            ];
            $data['metadata'] = array_merge($data['metadata'] ?? [], [
                'public_supplier_response' => true,
            ]);

            $proposal = $this->proposalService->createFromSupplierRequest($supplierRequest, $data);

            return LandingResponse::success([
                'proposal_number' => $proposal->proposal_number,
                'status' => $proposal->status->value,
                'status_label' => $proposal->status->label(),
            ], trans_message('procurement.public_supplier_requests.submitted'), 201);
        } catch (ValidationException $exception) {
            return LandingResponse::error(
                trans_message('procurement.public_supplier_requests.validation_error'),
                422,
                $exception->errors()
            );
        } catch (Throwable $exception) {
            Log::error('procurement.public_supplier_requests.submit.error', [
                'token_hash' => hash('sha256', $token),
                'exception' => $exception->getMessage(),
            ]);

            return LandingResponse::error(trans_message('procurement.public_supplier_requests.submit_error'), 500);
        }
    }

    private function findByToken(string $token): ?SupplierRequest
    {
        if (strlen($token) < 32) {
            return null;
        }

        return SupplierRequest::query()
            ->where('public_token', $token)
            ->with(['organization', 'supplier', 'externalSupplierContact', 'supplierParty', 'lines'])
            ->first();
    }

    private function blockedResponse(SupplierRequest $supplierRequest, bool $forSubmit): ?JsonResponse
    {
        if ($supplierRequest->status === SupplierRequestStatusEnum::CANCELLED) {
            return LandingResponse::error(
                trans_message('procurement.public_supplier_requests.cancelled'),
                Response::HTTP_GONE
            );
        }

        if ($supplierRequest->status === SupplierRequestStatusEnum::EXPIRED) {
            return LandingResponse::error(
                trans_message('procurement.public_supplier_requests.expired'),
                Response::HTTP_GONE
            );
        }

        if ($forSubmit && $supplierRequest->status === SupplierRequestStatusEnum::RESPONDED) {
            return LandingResponse::error(
                trans_message('procurement.public_supplier_requests.already_responded'),
                Response::HTTP_CONFLICT
            );
        }

        if ($forSubmit && !$supplierRequest->canReceivePublicProposal()) {
            return LandingResponse::error(
                trans_message('procurement.public_supplier_requests.not_accepting_responses'),
                Response::HTTP_CONFLICT
            );
        }

        return null;
    }
}
