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
use App\Services\Logging\LoggingService;

class SetOrganizationContext
{
    protected LoggingService $logging;
    
    public function __construct(LoggingService $logging)
    {
        $this->logging = $logging;
    }
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        $user = Auth::user();

        if (!$user) {
            $this->logging->technical('organization.context.skipped', [
                'reason' => 'no_authenticated_user',
                'uri' => $request->getRequestUri()
            ]);
            return $next($request);
        }

        // Если пользователь - LandingAdmin, то ему не нужен контекст организации
        if ($user instanceof LandingAdmin) {
            $this->logging->technical('organization.context.skipped', [
                'reason' => 'landing_admin_user',
                'user_id' => $user->id,
                'uri' => $request->getRequestUri()
            ]);
            return $next($request);
        }

        // ДИАГНОСТИКА: Начинаем установку контекста организации
        $this->logging->technical('organization.context.started', [
            'user_id' => $user->id,
            'uri' => $request->getRequestUri(),
            'method' => $request->method()
        ]);

        // Приводим к типу User для дальнейшей работы
        /** @var User $user */
        $organization = null;
        $organizationId = null;
        $logContext = ['user_id' => $user->id];

        try {
            // ДИАГНОСТИКА: Время парсинга JWT токена
            $jwtParseStart = microtime(true);
            $payload = JWTAuth::parseToken()->getPayload();
            $jwtParseDuration = (microtime(true) - $jwtParseStart) * 1000;
            
            $this->logging->technical('organization.context.jwt_parsed', [
                'user_id' => $user->id,
                'parse_duration_ms' => $jwtParseDuration
            ]);
            
            $organizationIdFromToken = $payload->get('organization_id');
            $logContext['token_org_id'] = $organizationIdFromToken;

            if ($organizationIdFromToken) {
                // ДИАГНОСТИКА: Время поиска организации в БД
                $orgLookupStart = microtime(true);
                $org = $user->organizations()->find($organizationIdFromToken);
                $orgLookupDuration = (microtime(true) - $orgLookupStart) * 1000;
                
                $this->logging->technical('organization.context.org_lookup', [
                    'user_id' => $user->id,
                    'organization_id' => $organizationIdFromToken,
                    'found' => $org !== null,
                    'lookup_duration_ms' => $orgLookupDuration
                ]);
                
                if ($org) {
                    $organization = $org;
                    $organizationId = $organizationIdFromToken;
                    $logContext['found_by'] = 'token';
                } else {
                    $logContext['token_org_id_user_mismatch'] = true;
                    
                    $this->logging->security('organization.context.token_mismatch', [
                        'user_id' => $user->id,
                        'token_org_id' => $organizationIdFromToken,
                        'user_has_access' => false
                    ], 'warning');
                }
            }

            if (!$organization) {
                $logContext['attempting_fallback'] = true;
                
                // ДИАГНОСТИКА: Время поиска первой организации пользователя
                $fallbackLookupStart = microtime(true);
                $firstOrg = $user->organizations()->first();
                $fallbackLookupDuration = (microtime(true) - $fallbackLookupStart) * 1000;
                
                $this->logging->technical('organization.context.fallback_lookup', [
                    'user_id' => $user->id,
                    'found' => $firstOrg !== null,
                    'lookup_duration_ms' => $fallbackLookupDuration
                ]);
                
                if ($firstOrg) {
                    $organization = $firstOrg;
                    $organizationId = $firstOrg->id;
                    $logContext['found_by'] = 'fallback_first';
                } else {
                    $logContext['no_organizations'] = true;
                    
                    $this->logging->business('organization.context.no_organizations', [
                        'user_id' => $user->id,
                        'user_email' => $user->email ?? 'unknown'
                    ], 'warning');
                }
            }

        } catch (JWTException $e) {
            $this->logging->technical('organization.context.jwt_exception', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e)
            ], 'warning');
            
            // ДИАГНОСТИКА: Fallback при JWT exception
            $fallbackStart = microtime(true);
            $firstOrg = $user->organizations()->first();
            $fallbackDuration = (microtime(true) - $fallbackStart) * 1000;
            
            $this->logging->technical('organization.context.jwt_fallback', [
                'user_id' => $user->id,
                'found' => $firstOrg !== null,
                'fallback_duration_ms' => $fallbackDuration
            ]);
            
            if ($firstOrg) {
                $organization = $firstOrg;
                $organizationId = $firstOrg->id;
                $logContext['found_by'] = 'fallback_no_token';
            }
        } catch (\Throwable $e) {
            $this->logging->technical('organization.context.unexpected_error', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'trace' => $e->getTraceAsString()
            ], 'error');
            
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
            
            $this->logging->business('organization.context.set', [
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'organization_name' => $organization->name ?? 'unknown',
                'found_by' => $logContext['found_by'] ?? 'unknown'
            ]);
        } else {
            $logContext['final_org_id'] = null;
            
            $this->logging->business('organization.context.failed', [
                'user_id' => $user->id,
                'reason' => 'no_organization_found'
            ], 'warning');
        }

        $totalDuration = (microtime(true) - $startTime) * 1000;
        
        $this->logging->technical('organization.context.completed', [
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'total_duration_ms' => $totalDuration,
            'success' => $organization !== null
        ]);
        
        if ($totalDuration > 500) {
            $this->logging->technical('organization.context.slow', [
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'total_duration_ms' => $totalDuration
            ], 'warning');
        }

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