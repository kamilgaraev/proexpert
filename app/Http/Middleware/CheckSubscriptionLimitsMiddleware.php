<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\Billing\UserSubscriptionService;
use Symfony\Component\HttpFoundation\Response;

class CheckSubscriptionLimitsMiddleware
{
    protected UserSubscriptionService $subscriptionService;

    public function __construct(UserSubscriptionService $subscriptionService)
    {
        $this->subscriptionService = $subscriptionService;
    }

    public function handle(Request $request, Closure $next, string $limitType): Response
    {
        $user = Auth::user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Пользователь не авторизован'
            ], 401);
        }

        $canProceed = $this->subscriptionService->checkUserLimit($user, $limitType);
        
        if (!$canProceed) {
            return response()->json([
                'success' => false,
                'message' => $this->getLimitExceededMessage($limitType),
                'error_code' => 'SUBSCRIPTION_LIMIT_EXCEEDED',
                'limit_type' => $limitType
            ], 403);
        }

        return $next($request);
    }

    private function getLimitExceededMessage(string $limitType): string
    {
        return match ($limitType) {
            'max_foremen' => 'Достигнут лимит количества прорабов. Обновите подписку для добавления новых прорабов.',
            'max_projects' => 'Достигнут лимит количества проектов. Обновите подписку для создания новых проектов.',
            'max_storage_mb' => 'Достигнут лимит дискового пространства. Обновите подписку для загрузки файлов.',
            default => 'Достигнут лимит подписки. Обновите подписку для продолжения работы.',
        };
    }
} 