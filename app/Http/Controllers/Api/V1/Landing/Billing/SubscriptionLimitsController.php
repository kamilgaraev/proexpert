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
        
        // Кешируем лимиты подписки на 5 минут
        $cacheKey = "subscription_limits_{$user->id}_{$user->current_organization_id}";
        $limitsData = \Illuminate\Support\Facades\Cache::remember($cacheKey, 300, function () use ($user) {
            return $this->limitsService->getUserLimitsData($user);
        });
        
        return response()->json([
            'success' => true,
            'data' => new SubscriptionLimitsResource($limitsData)
        ]);
    }

} 