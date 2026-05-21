<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing\Billing;

use App\Http\Controllers\Controller;
use App\Http\Resources\Billing\SubscriptionPlanResource;
use App\Http\Responses\LandingResponse;
use App\Interfaces\Billing\SubscriptionPlanServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function trans_message;

class SubscriptionPlanController extends Controller
{
    public function __construct(
        protected SubscriptionPlanServiceInterface $planService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            return LandingResponse::success(
                SubscriptionPlanResource::collection($this->planService->getActivePlans()),
                trans_message('billing.plans.loaded')
            );
        } catch (Throwable $exception) {
            Log::error('landing.billing.plans.load_failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return LandingResponse::error(
                trans_message('billing.plans.load_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
