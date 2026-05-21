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
                'message' => 'РџРѕР»СЊР·РѕРІР°С‚РµР»СЊ РЅРµ Р°РІС‚РѕСЂРёР·РѕРІР°РЅ'
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
            'max_foremen' => 'Р”РѕСЃС‚РёРіРЅСѓС‚ Р»РёРјРёС‚ РєРѕР»РёС‡РµСЃС‚РІР° РїСЂРѕСЂР°Р±РѕРІ. РћР±РЅРѕРІРёС‚Рµ РїРѕРґРїРёСЃРєСѓ РґР»СЏ РґРѕР±Р°РІР»РµРЅРёСЏ РЅРѕРІС‹С… РїСЂРѕСЂР°Р±РѕРІ.',
            'max_projects' => 'Р”РѕСЃС‚РёРіРЅСѓС‚ Р»РёРјРёС‚ РєРѕР»РёС‡РµСЃС‚РІР° РїСЂРѕРµРєС‚РѕРІ. РћР±РЅРѕРІРёС‚Рµ РїРѕРґРїРёСЃРєСѓ РґР»СЏ СЃРѕР·РґР°РЅРёСЏ РЅРѕРІС‹С… РїСЂРѕРµРєС‚РѕРІ.',
            'max_storage_mb' => 'Р”РѕСЃС‚РёРіРЅСѓС‚ Р»РёРјРёС‚ РґРёСЃРєРѕРІРѕРіРѕ РїСЂРѕСЃС‚СЂР°РЅСЃС‚РІР°. РћР±РЅРѕРІРёС‚Рµ РїРѕРґРїРёСЃРєСѓ РґР»СЏ Р·Р°РіСЂСѓР·РєРё С„Р°Р№Р»РѕРІ.',
            default => 'Р”РѕСЃС‚РёРіРЅСѓС‚ Р»РёРјРёС‚ РїРѕРґРїРёСЃРєРё. РћР±РЅРѕРІРёС‚Рµ РїРѕРґРїРёСЃРєСѓ РґР»СЏ РїСЂРѕРґРѕР»Р¶РµРЅРёСЏ СЂР°Р±РѕС‚С‹.',
        };
    }
} 