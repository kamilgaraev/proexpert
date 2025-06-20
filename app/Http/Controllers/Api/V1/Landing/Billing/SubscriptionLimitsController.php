<?php

namespace App\Http\Controllers\Api\V1\Landing\Billing;

use App\Http\Controllers\Controller;
use App\Services\Billing\SubscriptionLimitsService;
use App\Http\Resources\Billing\SubscriptionLimitsResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class SubscriptionLimitsController extends Controller
{
    protected SubscriptionLimitsService $limitsService;

    public function __construct(SubscriptionLimitsService $limitsService)
    {
        $this->limitsService = $limitsService;
    }

    /**
     * Получить информацию о лимитах текущей подписки пользователя
     */
    public function show(Request $request): JsonResponse
    {
        $user = Auth::user();
        $limitsData = $this->limitsService->getUserLimitsData($user);
        
        return response()->json([
            'success' => true,
            'data' => new SubscriptionLimitsResource($limitsData)
        ]);
    }

} 