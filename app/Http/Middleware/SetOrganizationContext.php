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
use App\Models\LandingAdmin;
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
        $user = Auth::user();

        if (!$user) {
            Log::debug('[SetOrganizationContext] User not authenticated.');
            return $next($request);
        }

        // Если пользователь - LandingAdmin, то ему не нужен контекст организации
        if ($user instanceof LandingAdmin) {
            Log::debug('[SetOrganizationContext] LandingAdmin detected, skipping organization context.');
            return $next($request);
        }

        // Приводим к типу User для дальнейшей работы
        /** @var User $user */
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
                    $logContext['no_organizations'] = true;
                }
            }

        } catch (JWTException $e) {
            Log::debug('[SetOrganizationContext] JWT parsing failed: ' . $e->getMessage());
            $firstOrg = $user->organizations()->first();
            if ($firstOrg) {
                $organization = $firstOrg;
                $organizationId = $firstOrg->id;
                $logContext['found_by'] = 'fallback_no_token';
            }
        } catch (\Throwable $e) {
            Log::error('[SetOrganizationContext] Unexpected error: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'trace' => $e->getTraceAsString()
            ]);
        }

        if ($organization) {
            $user->current_organization_id = $organizationId;
            $request->attributes->set('current_organization_id', $organizationId);
            $request->attributes->set('current_organization', $organization);

            App::instance(OrganizationContext::class, new OrganizationContext($organization));

            $logContext['final_org_id'] = $organizationId;
        } else {
            $logContext['final_org_id'] = null;
        }

        Log::debug('[SetOrganizationContext] Context set', $logContext);

        return $next($request);
    }

    /**
     * Handle tasks after the response has been sent to the browser.
     *
     * Добавляем пустой метод terminate, чтобы избежать ошибки вызова 
     * несуществующего метода ядром Laravel, если оно по какой-то причине
     * считает этот middleware "terminable".
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     * @return void
     */
    public function terminate(Request $request, Response $response): void
    {
        // Пока ничего не делаем здесь
    }
} 