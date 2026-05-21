<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing\Billing;

use App\Http\Controllers\Controller;
use App\Http\Resources\Billing\SubscriptionLimitsResource;
use App\Http\Responses\LandingResponse;
use App\Services\Billing\SubscriptionLimitsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

use function trans_message;

class SubscriptionLimitsController extends Controller
{
    public function __construct(
        protected SubscriptionLimitsService $limitsService
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $user = Auth::user();
        $cacheKey = "subscription_limits_{$user->id}_{$user->current_organization_id}";

        $limitsData = Cache::remember($cacheKey, 300, function () use ($user) {
            return $this->limitsService->getUserLimitsData($user);
        });

        return LandingResponse::success(
            new SubscriptionLimitsResource($limitsData),
            trans_message('landing.subscription_limits.loaded')
        );
    }
}
