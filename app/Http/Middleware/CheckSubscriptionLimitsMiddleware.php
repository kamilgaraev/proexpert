<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Interfaces\Billing\SubscriptionLimitsServiceInterface;
use Symfony\Component\HttpFoundation\Response;

class CheckSubscriptionLimitsMiddleware
{
    protected SubscriptionLimitsServiceInterface $limitsService;

    public function __construct(SubscriptionLimitsServiceInterface $limitsService)
    {
        $this->limitsService = $limitsService;
    }

    public function handle(Request $request, Closure $next, string $limitType): Response
    {
        $user = Auth::user();
        
        if (!$user) {
            return \App\Http\Responses\AdminResponse::fromPayload([
                'success' => false,
                'message' => trans_message('errors.unauthenticated')
            ], 401);
        }

        $canProceed = $this->checkUserLimit($user, $limitType);
        
        if (!$canProceed) {
            return \App\Http\Responses\AdminResponse::fromPayload([
                'success' => false,
                'message' => $this->getLimitExceededMessage($limitType),
                'error_code' => 'SUBSCRIPTION_LIMIT_EXCEEDED',
                'limit_type' => $limitType
            ], 403);
        }

        return $next($request);
    }

    private function checkUserLimit($user, string $limitType): bool
    {
        return match ($limitType) {
            'max_foremen' => $this->limitsService->canCreateForeman($user),
            'max_projects' => $this->limitsService->canCreateProject($user),
            'max_users' => $this->limitsService->canCreateUser($user),
            'max_contractor_invitations' => $this->limitsService->canCreateContractorInvitation($user),
            default => true,
        };
    }

    private function getLimitExceededMessage(string $limitType): string
    {
        return match ($limitType) {
            'max_foremen' => trans_message('billing.limits.max_foremen'),
            'max_projects' => trans_message('billing.limits.max_projects'),
            'max_storage_mb' => trans_message('billing.limits.max_storage_mb'),
            default => trans_message('billing.limits.default'),
        };
    }
}
