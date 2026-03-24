<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SiteRequests\Http\Controllers;

use App\BusinessModules\Core\Payments\Http\Resources\PaymentDocumentResource;
use App\BusinessModules\Features\SiteRequests\Http\Requests\CreatePaymentFromRequestsRequest;
use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestResource;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestPaymentService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use function trans_message;

class SiteRequestPaymentController extends Controller
{
    public function __construct(
        private readonly SiteRequestPaymentService $paymentService
    ) {
    }

    public function getAvailableForPayment(Request $request): JsonResponse
    {
        try {
            $requests = $this->paymentService->getAvailableForPayment(
                (int) $request->attributes->get('current_organization_id'),
                $request->only(['project_id', 'request_type', 'search'])
            );

            return AdminResponse::success([
                'items' => SiteRequestResource::collection($requests)->resolve(),
                'count' => $requests->count(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[SiteRequestPaymentController.getAvailableForPayment] Unexpected error', [
                'message' => $e->getMessage(),
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(trans_message('site_requests.payment_available_load_error'), 500);
        }
    }

    public function createPayment(CreatePaymentFromRequestsRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $validated['created_by_user_id'] = $request->user()?->id;

            $paymentDocument = $this->paymentService->createPaymentFromRequests(
                (int) $request->attributes->get('current_organization_id'),
                $validated['request_ids'],
                $validated
            );

            return AdminResponse::success(
                new PaymentDocumentResource($paymentDocument),
                trans_message('site_requests.payment_created'),
                Response::HTTP_CREATED
            );
        } catch (\InvalidArgumentException|\DomainException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            Log::error('[SiteRequestPaymentController.createPayment] Unexpected error', [
                'message' => $e->getMessage(),
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'request_ids' => $request->validated()['request_ids'] ?? [],
            ]);

            return AdminResponse::error(trans_message('site_requests.payment_create_error'), 500);
        }
    }
}
