<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\App;
use App\Services\Organization\OrganizationContext;

class SetOrganizationContext
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (!$user) {
            Log::debug('[SetOrganizationContext] User not authenticated.');
            return $next($request);
        }

        $organization = null;
        $organizationId = null;
        $logContext = ['user_id' => $user->id];

        try {
            $payload = JWTAuth::parseToken()->getPayload();
            $organizationIdFromToken = $payload->get('organization_id');
            $logContext['token_org_id'] = $organizationIdFromToken;

            if ($organizationIdFromToken) {
                $org = $user->organizations()->find($organizationIdFromToken);
                if ($org) {
                    $organization = $org;
                    $organizationId = $organizationIdFromToken;
                    $logContext['found_by'] = 'token';
                } else {
                    $logContext['token_org_id_user_mismatch'] = true;
                }
            }

            if (!$organization) {
                $logContext['attempting_fallback'] = true;
                $firstOrg = $user->organizations()->first();
                if ($firstOrg) {
                    $organization = $firstOrg;
                    $organizationId = $firstOrg->id;
                    $logContext['found_by'] = 'fallback_first';
                } else {
                    $logContext['found_by'] = 'none';
                    $logContext['user_has_no_organizations'] = true;
                }
            }

        } catch (JWTException $e) {
             report($e);
             $logContext['jwt_exception'] = $e->getMessage();
        }

        $logContext['final_org_id'] = $organizationId;
        if ($organization) {
            // Устанавливаем атрибуты запроса
            $request->attributes->set('current_organization', $organization);
            $request->attributes->set('current_organization_id', $organizationId);
            $logContext['attribute_set'] = true;
             
            // Обновляем контекст организации через статические методы
            try {
                // Используем статические методы класса
                OrganizationContext::setOrganizationId($organizationId);
                OrganizationContext::setOrganization($organization);
                
                Log::debug('[SetOrganizationContext] Context updated via static methods.', [
                    'org_id' => $organizationId
                ]);
            } catch (\Throwable $e) {
                Log::error('[SetOrganizationContext] Failed to update context.', [
                    'error' => $e->getMessage(), 
                    'trace' => $e->getTraceAsString()
                ]);
            }
        } else {
            $logContext['attribute_set'] = false;
        }
        
        Log::debug('[SetOrganizationContext] Context determination result.', $logContext);

        return $next($request);
    }
} 