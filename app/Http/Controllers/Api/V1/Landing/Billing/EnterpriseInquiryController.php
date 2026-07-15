<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing\Billing;

use App\DTOs\Billing\EnterpriseInquiryData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Landing\Billing\StoreEnterpriseInquiryRequest;
use App\Http\Responses\LandingResponse;
use App\Models\User;
use App\Services\Billing\EnterpriseInquiryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnterpriseInquiryController extends Controller
{
    public function __construct(
        private readonly EnterpriseInquiryService $inquiryService,
    ) {}

    public function __invoke(StoreEnterpriseInquiryRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $organizationId = (int) $request->attributes->get('current_organization_id');

        try {
            $inquiry = $this->inquiryService->create(
                $user,
                $organizationId,
                EnterpriseInquiryData::fromValidated($request->validated()),
            );

            return LandingResponse::success([
                'inquiry_id' => $inquiry->id,
                'status' => $inquiry->status,
                'created_at' => $inquiry->created_at?->toIso8601String(),
            ], trans_message('billing.commercial.enterprise_inquiry_created'), Response::HTTP_CREATED);
        } catch (Throwable $exception) {
            Log::error('billing.enterprise_inquiry.failed', [
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'client_request_id' => $request->input('client_request_id'),
                'error' => $exception->getMessage(),
            ]);

            return LandingResponse::error(
                trans_message('billing.commercial.enterprise_inquiry_failed'),
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }
}
