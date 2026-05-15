<?php

namespace App\Services\Auth;

use App\DTOs\Auth\LoginDTO;
use App\DTOs\Auth\RegisterDTO;
use App\Models\User;
use App\Models\Organization;
use App\Notifications\LandingResetPasswordNotification;
use App\Repositories\Interfaces\OrganizationRepositoryInterface;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\Services\LogService;
use App\Services\PerformanceMonitor;
use App\Services\Auth\UserAuthSessionService;
use App\Interfaces\Billing\BalanceServiceInterface;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\UserWelcomeMail;

class JwtAuthService
{
    protected UserRepositoryInterface $userRepository;
    protected OrganizationRepositoryInterface $organizationRepository;
    protected BalanceServiceInterface $balanceService;
    protected UserAuthSessionService $authSessionService;

    /**
     * РљРѕРЅСЃС‚СЂСѓРєС‚РѕСЂ СЃРµСЂРІРёСЃР° Р°СѓС‚РµРЅС‚РёС„РёРєР°С†РёРё.
     *
     * @param UserRepositoryInterface $userRepository
     * @param OrganizationRepositoryInterface $organizationRepository
     * @param BalanceServiceInterface $balanceService
     */
    public function __construct(
        UserRepositoryInterface $userRepository,
        OrganizationRepositoryInterface $organizationRepository,
        BalanceServiceInterface $balanceService,
        UserAuthSessionService $authSessionService
    ) {
        $this->userRepository = $userRepository;
        $this->organizationRepository = $organizationRepository;
        $this->balanceService = $balanceService;
        $this->authSessionService = $authSessionService;
    }

    /**
     * РђСѓС‚РµРЅС‚РёС„РёРєР°С†РёСЏ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ Рё РїРѕР»СѓС‡РµРЅРёРµ С‚РѕРєРµРЅР° JWT.
     *
     * @param LoginDTO $loginDTO
     * @param string $guard
     * @return array
     */
    public function authenticate(LoginDTO $loginDTO, string $guard): array
    {
        Log::info('[JwtAuthService] authenticate method entered.');
        // РЈР±РёСЂР°РµРј PerformanceMonitor РІСЂРµРјРµРЅРЅРѕ РґР»СЏ РґРёР°РіРЅРѕСЃС‚РёРєРё
        // return PerformanceMonitor::measure('auth.login', function() use ($loginDTO, $guard) { 
            $logContext = [];
            try {
                Log::info('[JwtAuthService] Inside main try block.');
                Auth::shouldUse($guard);
                $logContext = [
                    'email' => $loginDTO->email,
                    'guard' => $guard,
                    'ip' => request()->ip(),
                    'user_agent' => request()->header('User-Agent')
                ];
                Log::info('[JwtAuthService] Log context prepared.', $logContext);

                $credentials = $loginDTO->toArray();
                Log::info('[JwtAuthService] Credentials prepared, entering inner try block.');
                try {
                    Log::info('[JwtAuthService] Trying Auth::validate.', ['email' => $credentials['email'] ?? 'N/A', 'guard' => $guard]);
                    if (!Auth::validate($credentials)) {
                        Log::warning('[JwtAuthService] Auth::validate FAILED.', ['email' => $credentials['email'] ?? 'N/A', 'guard' => $guard]);
                        LogService::authLog('login_failed', array_merge($logContext, ['reason' => 'credentials_invalid']));
                        return ['success' => false, 'message' => trans_message('auth.login_failed'), 'status_code' => 401];
                    }
                    Log::info('[JwtAuthService] Auth::validate passed. Before Auth::getLastAttempted.');

                    /** @var User $user */
                    $user = Auth::getLastAttempted();
                    Log::info('[JwtAuthService] User retrieved.', ['user_id' => $user?->id]);
                    
                    if (!$user->hasVerifiedEmail()) {
                        Log::warning('[JwtAuthService] Login blocked: email not verified', [
                            'user_id' => $user->id,
                            'email' => $user->email
                        ]);
                        LogService::authLog('login_failed', array_merge($logContext, [
                            'reason' => 'email_not_verified',
                            'user_id' => $user->id
                        ]));
                        return [
                            'success' => false, 
                            'message' => trans_message('auth.email_verification_required'), 
                            'status_code' => 403
                        ];
                    }
                    
                    $user->update([
                        'last_login_at' => now(),
                        'last_login_ip' => request()->ip(),
                    ]);
                    Log::info('[JwtAuthService] User last login updated.');

                    // Р—Р°РіСЂСѓР¶Р°РµРј РѕС‚РЅРѕС€РµРЅРёСЏ СЃ РЅРѕРІРѕР№ СЃРёСЃС‚РµРјРѕР№ Р°РІС‚РѕСЂРёР·Р°С†РёРё (СЃ fallback)
                    $assignmentsCount = 0;
                    try {
                        $user->load('roleAssignments');
                        $assignmentsCount = $user->roleAssignments->count();
                        Log::info('[JwtAuthService] User role assignments loaded (new auth system).', [
                            'user_id' => $user->id,
                            'assignments_count' => $assignmentsCount
                        ]);
                    } catch (\Exception $e) {
                        Log::warning('[JwtAuthService] New auth system tables not ready, skipping role assignments check', [
                            'user_id' => $user->id,
                            'error' => $e->getMessage()
                        ]);
                        // РџСЂРѕРґРѕР»Р¶Р°РµРј Р±РµР· РїСЂРѕРІРµСЂРєРё СЂРѕР»РµР№, РїРѕРєР° РЅРµ СЃРѕР·РґР°РЅС‹ С‚Р°Р±Р»РёС†С‹ РЅРѕРІРѕР№ СЃРёСЃС‚РµРјС‹
                    }
                    
                    // Р•СЃР»Рё РЅР°Р·РЅР°С‡РµРЅРёР№ СЂРѕР»РµР№ РЅРµС‚ РІРѕРѕР±С‰Рµ, РїСЂРѕРІРµСЂСЏРµРј Рё РІРѕСЃСЃС‚Р°РЅР°РІР»РёРІР°РµРј СЂРѕР»СЊ РІР»Р°РґРµР»СЊС†Р°
                    if ($assignmentsCount === 0) {
                        Log::warning('[JwtAuthService] User has no roles, checking organizations.', [
                            'user_id' => $user->id,
                        ]);
                        
                        // РџСЂРѕРІРµСЂСЏРµРј, РµСЃС‚СЊ Р»Рё Сѓ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ РѕСЂРіР°РЅРёР·Р°С†РёРё
                        $ownerOrganization = $user->organizations()
                            ->wherePivot('is_owner', true)
                            ->wherePivot('is_active', true)
                            ->first();

                        if ($ownerOrganization) {
                            Log::info('[JwtAuthService] User has organizations but no roles. Fixing role for first organization.', [
                                'user_id' => $user->id,
                                'organizations_count' => 1
                            ]);
                            
                            // Р‘РµСЂРµРј РїРµСЂРІСѓСЋ РѕСЂРіР°РЅРёР·Р°С†РёСЋ Рё РЅР°Р·РЅР°С‡Р°РµРј СЂРѕР»СЊ РІР»Р°РґРµР»СЊС†Р°
                            $firstOrg = $ownerOrganization;
                            
                            // РќР°Р·РЅР°С‡Р°РµРј СЂРѕР»СЊ РІР»Р°РґРµР»СЊС†Р° С‡РµСЂРµР· РЅРѕРІСѓСЋ СЃРёСЃС‚РµРјСѓ Р°РІС‚РѕСЂРёР·Р°С†РёРё
                            try {
                                $this->userRepository->assignRoleToUser($user->id, 'organization_owner', $firstOrg->id);
                                Log::info('[JwtAuthService] Fixed: Owner role assigned (new auth system)', [
                                    'user_id' => $user->id,
                                    'organization_id' => $firstOrg->id,
                                    'role_slug' => 'organization_owner'
                                ]);
                            } catch (\Exception $roleException) {
                                Log::warning('[JwtAuthService] Cannot assign owner role - new auth system tables not ready', [
                                    'user_id' => $user->id,
                                    'organization_id' => $firstOrg->id,
                                    'error' => $roleException->getMessage()
                                ]);
                                // РќРµ РєСЂРёС‚РёС‡РЅР°СЏ РѕС€РёР±РєР° - СЂРѕР»Рё Р±СѓРґСѓС‚ РЅР°Р·РЅР°С‡РµРЅС‹ РїРѕСЃР»Рµ СЃРѕР·РґР°РЅРёСЏ С‚Р°Р±Р»РёС†
                            }
                        } else {
                            Log::warning('[JwtAuthService] User has no roles and no owner organization. Role repair skipped.', [
                                'user_id' => $user->id,
                            ]);
                        }
                    }

                    // РћРїСЂРµРґРµР»СЏРµРј ID РѕСЂРіР°РЅРёР·Р°С†РёРё (РїРѕРєР° РїСЂРѕСЃС‚Рѕ РїРµСЂРІР°СЏ)
                    $userOrganizations = $user->organizations()->pluck('organizations.id')->toArray(); // <-- РЈРєР°Р·С‹РІР°РµРј С‚Р°Р±Р»РёС†Сѓ organizations.id
                    Log::info('[JwtAuthService] User organizations IDs.', ['user_id' => $user->id, 'organization_ids' => $userOrganizations]); // Р›РѕРіРёСЂСѓРµРј РІСЃРµ РѕСЂРіР°РЅРёР·Р°С†РёРё
                    
                    // РЇРІРЅРѕ РІС‹Р±РёСЂР°РµРј ID РѕСЂРіР°РЅРёР·Р°С†РёРё РїРµСЂРµРґ first()
                    $organizationId = $user->organizations()->select('organizations.id')->first()?->id; // <-- РЈРєР°Р·С‹РІР°РµРј С‚Р°Р±Р»РёС†Сѓ Рё РІС‹Р±РёСЂР°РµРј organisations.id
                    Log::info('[JwtAuthService] Organization ID determined for token (using first).', ['user_id' => $user->id, 'selected_org_id' => $organizationId]); // Р›РѕРіРёСЂСѓРµРј РІС‹Р±СЂР°РЅРЅСѓСЋ

                    // РЈСЃС‚Р°РЅР°РІР»РёРІР°РµРј current_organization_id РґР»СЏ РѕР±СЉРµРєС‚Р° User, РєРѕС‚РѕСЂС‹Р№ РІРµСЂРЅРµС‚СЃСЏ РІ РєРѕРЅС‚СЂРѕР»Р»РµСЂ
                    // Р­С‚Рѕ РІР°Р¶РЅРѕ РґР»СЏ РєРѕСЂСЂРµРєС‚РЅРѕР№ СЂР°Р±РѕС‚С‹ Gate РІ РєРѕРЅС‚СЂРѕР»Р»РµСЂРµ
                    if ($organizationId && $user->current_organization_id !== $organizationId) {
                         $user->current_organization_id = $organizationId;
                         $user->save();
                         Log::info('[JwtAuthService] User object\'s current_organization_id set (was different).', ['user_id' => $user->id, 'org_id' => $organizationId]);
                    } elseif (!$user->current_organization_id && $organizationId) {
                        $user->current_organization_id = $organizationId;
                        $user->save();
                         Log::info('[JwtAuthService] User object\'s current_organization_id set (was null).', ['user_id' => $user->id, 'org_id' => $organizationId]);
                    } else {
                         Log::info('[JwtAuthService] User object\'s current_organization_id not changed.', ['user_id' => $user->id, 'existing_org_id' => $user->current_organization_id, 'determined_org_id' => $organizationId]); // Р›РѕРіРёСЂСѓРµРј, РµСЃР»Рё РЅРµ РјРµРЅСЏР»Рё
                    }

                    // Р“РµРЅРµСЂРёСЂСѓРµРј С‚РѕРєРµРЅ
                    $customClaims = ['organization_id' => $organizationId];
                    if ((bool) config('auth_tokens.sessions.enabled', true)) {
                        $authSession = $this->authSessionService->createForLogin(
                            $user,
                            $organizationId ? (int) $organizationId : null,
                            request()
                        );
                        $customClaims['session_uuid'] = $authSession->session_uuid;
                    }
                    $token = JWTAuth::claims($customClaims)->fromUser($user);
                    Log::info('[JwtAuthService] JWT token generated.');

                    LogService::authLog('login_success', array_merge($logContext, ['user_id' => $user->id, 'organization_id' => $organizationId]));
                    // Р’РѕР·РІСЂР°С‰Р°РµРј $user СЃ СѓСЃС‚Р°РЅРѕРІР»РµРЅРЅС‹Рј (РЅР°РґРµРµРјСЃСЏ) current_organization_id
                    return ['success' => true, 'token' => $token, 'user' => $user, 'status_code' => 200];

                } catch (JWTException $e) {
                    Log::error('[JwtAuthService] JWTException caught.', ['error' => $e->getMessage()]);
                    $errorContext = array_merge($logContext, [
                        'action' => 'login_jwt_error',
                        'guard' => $guard,
                        'error_message' => $e->getMessage(),
                        'error_code' => $e->getCode()
                    ]);
                    try {
                        $errorContext['email'] = $loginDTO->email;
                    } catch (\Exception $ex) {
                    }
                    
                    LogService::exception($e, $errorContext);
                    
                    return [
                        'success' => false,
                        'message' => 'РћС€РёР±РєР° СЃРѕР·РґР°РЅРёСЏ С‚РѕРєРµРЅР° JWT',
                        'status_code' => 500
                    ];
                }
            } catch (\Throwable $e) {
                Log::error('[JwtAuthService] Throwable caught in outer catch block.', ['error' => $e->getMessage()]); 
                $errorContext = array_merge($logContext, [
                    'action' => 'login_unexpected_error',
                    'guard' => $guard,
                    'error_message' => $e->getMessage(),
                ]);
                try {
                    $errorContext['email'] = $loginDTO->email;
                } catch (\Exception $ex) {
                }

                // Р—Р°РјРµРЅСЏРµРј LogService::exception РЅР° СЃС‚Р°РЅРґР°СЂС‚РЅС‹Р№ Log::error СЃ РїРѕР»РЅС‹Рј СЃС‚РµРєРѕРј
                // Log::info('[JwtAuthService] Before calling LogService::exception in outer catch.');
                // LogService::exception($e, $errorContext); 
                // Log::info('[JwtAuthService] After calling LogService::exception in outer catch.');
                Log::error('[JwtAuthService] Unexpected Authentication Error', [
                    'context' => $errorContext,
                    'exception_class' => get_class($e),
                    'exception_message' => $e->getMessage(),
                    'exception_trace' => $e->getTraceAsString() // Р›РѕРіРёСЂСѓРµРј РїРѕР»РЅС‹Р№ СЃС‚РµРє
                ]);

                return [
                    'success' => false,
                    'message' => 'Р’РЅСѓС‚СЂРµРЅРЅСЏСЏ РѕС€РёР±РєР° СЃРµСЂРІРµСЂР° РїСЂРё Р°СѓС‚РµРЅС‚РёС„РёРєР°С†РёРё.',
                    'status_code' => 500
                ];
            }
        // }); // РљРѕРЅРµС† PerformanceMonitor
    }

    /**
     * РџРѕР»СѓС‡РµРЅРёРµ РёРЅС„РѕСЂРјР°С†РёРё Рѕ С‚РµРєСѓС‰РµРј РїРѕР»СЊР·РѕРІР°С‚РµР»Рµ.
     *
     * @param string $guard
     * @return array
     */
    public function me(string $guard): array
    {
        try {
            Auth::shouldUse($guard);
            /** @var User $user */
            $user = Auth::user();

            if (!$user) {
                LogService::authLog('profile_access_failed', [
                    'guard' => $guard,
                    'reason' => 'not_authenticated',
                    'ip' => request()->ip()
                ]);
                
                return [
                    'success' => false,
                    'message' => 'РџРѕР»СЊР·РѕРІР°С‚РµР»СЊ РЅРµ Р°СѓС‚РµРЅС‚РёС„РёС†РёСЂРѕРІР°РЅ',
                    'status_code' => 401
                ];
            }

            // Р—Р°РіСЂСѓР¶Р°РµРј РґРѕРїРѕР»РЅРёС‚РµР»СЊРЅС‹Рµ РґР°РЅРЅС‹Рµ СЃ РєСЌС€РёСЂРѕРІР°РЅРёРµРј
            $cacheKey = "user_with_roles_{$user->id}_" . ($user->current_organization_id ?? 'no_org');
            $userWithRoles = cache()->remember($cacheKey, 300, function() use ($user) {
                return $this->userRepository->findWithRoles($user->id);
            });
            
            if (!$userWithRoles) {
                Log::warning('[JwtAuthService::me] User not found by findWithRoles', ['user_id' => $user->id]);
            } else {
                $user = $userWithRoles;
            }
            
            LogService::authLog('profile_access', [
                'user_id' => $user->id,
                'guard' => $guard,
                'ip' => request()->ip()
            ]);

            return [
                'success' => true,
                'user' => $user,
                'status_code' => 200
            ];
        } catch (TokenExpiredException $e) {
            LogService::authLog('profile_access_failed', [
                'guard' => $guard,
                'reason' => 'token_expired',
                'ip' => request()->ip()
            ]);
            
            return [
                'success' => false,
                'message' => 'РўРѕРєРµРЅ РїСЂРѕСЃСЂРѕС‡РµРЅ',
                'status_code' => 401
            ];
        } catch (TokenInvalidException $e) {
            LogService::authLog('profile_access_failed', [
                'guard' => $guard,
                'reason' => 'token_invalid',
                'ip' => request()->ip()
            ]);
            
            return [
                'success' => false,
                'message' => 'РќРµРґРµР№СЃС‚РІРёС‚РµР»СЊРЅС‹Р№ С‚РѕРєРµРЅ',
                'status_code' => 401
            ];
        } catch (JWTException $e) {
            LogService::exception($e, [
                'action' => 'profile_access',
                'guard' => $guard,
                'ip' => request()->ip()
            ]);
            
            return [
                'success' => false,
                'message' => 'РўРѕРєРµРЅ РѕС‚СЃСѓС‚СЃС‚РІСѓРµС‚',
                'status_code' => 401
            ];
        }
    }

    /**
     * РџРѕР»СѓС‡РµРЅРёРµ ID С‚РµРєСѓС‰РµР№ РѕСЂРіР°РЅРёР·Р°С†РёРё РёР· JWT С‚РѕРєРµРЅР°.
     *
     * @return int|null ID РѕСЂРіР°РЅРёР·Р°С†РёРё РёР»Рё null, РµСЃР»Рё С‚РѕРєРµРЅ РЅРµ СЃРѕРґРµСЂР¶РёС‚ organization_id.
     */
    public function sendResetLink(string $email): array
    {
        $user = $this->userRepository->findByEmail($email);

        if (!$user instanceof User) {
            return [
                'success' => true,
                'status_code' => 200,
            ];
        }

        $token = Password::broker('users')->createToken($user);
        $url = sprintf(
            '%s/reset-password?token=%s&email=%s',
            rtrim((string) config('app.frontend_url'), '/'),
            urlencode($token),
            urlencode($user->email)
        );

        $user->notify(new LandingResetPasswordNotification($url));

        return [
            'success' => true,
            'status_code' => 200,
        ];
    }

    public function resetPassword(array $payload): array
    {
        $status = Password::broker('users')->reset(
            [
                'email' => $payload['email'],
                'password' => $payload['password'],
                'password_confirmation' => $payload['password_confirmation'],
                'token' => $payload['token'],
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return [
                'success' => false,
                'status_code' => 422,
                'message' => trans_message('auth.password_reset.invalid'),
            ];
        }

        return [
            'success' => true,
            'status_code' => 200,
        ];
    }

    public function getCurrentOrganizationId(): ?int
    {
        try {
            $payload = JWTAuth::parseToken()->getPayload();
            // РџСЂРµРґРїРѕР»Р°РіР°РµРј, С‡С‚Рѕ ID РѕСЂРіР°РЅРёР·Р°С†РёРё С…СЂР°РЅРёС‚СЃСЏ РІ claim 'organization_id'
            return $payload->get('organization_id');
        } catch (JWTException $e) {
            // РћР±СЂР°Р±РѕС‚РєР° СЃР»СѓС‡Р°РµРІ, РєРѕРіРґР° С‚РѕРєРµРЅ РЅРµРІР°Р»РёРґРµРЅ, РѕС‚СЃСѓС‚СЃС‚РІСѓРµС‚ РёР»Рё РЅРµ СЃРѕРґРµСЂР¶РёС‚ РЅСѓР¶РЅРѕРіРѕ claim
            LogService::exception($e, [
                'action' => 'get_current_organization_id',
                'ip' => request()->ip()
            ]);
            return null;
        }
    }

    /**
     * РћР±РЅРѕРІР»РµРЅРёРµ С‚РѕРєРµРЅР° JWT.
     *
     * @param string $guard
     * @return array
     */
    public function refresh(string $guard): array
    {
        try {
            Auth::shouldUse($guard);
            $payload = JWTAuth::parseToken()->getPayload();
            $claims = array_filter([
                'organization_id' => $payload->get('organization_id'),
                'session_uuid' => $payload->get('session_uuid'),
            ], static fn ($value) => $value !== null);
            $token = auth($guard)->claims($claims)->refresh();
            
            // РџРѕР»СѓС‡Р°РµРј РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ РїРѕСЃР»Рµ РѕР±РЅРѕРІР»РµРЅРёСЏ С‚РѕРєРµРЅР°
            $user = Auth::user();
            
            LogService::authLog('token_refresh', [
                'user_id' => $user ? $user->id : null,
                'guard' => $guard,
                'ip' => request()->ip()
            ]);

            return [
                'success' => true,
                'token' => $token,
                'status_code' => 200
            ];
        } catch (TokenExpiredException $e) {
            LogService::authLog('token_refresh_failed', [
                'guard' => $guard,
                'reason' => 'token_expired',
                'ip' => request()->ip()
            ]);
            
            return [
                'success' => false,
                'message' => trans_message('auth.token_error'),
                'status_code' => 401
            ];
        } catch (JWTException $e) {
            LogService::exception($e, [
                'action' => 'token_refresh',
                'guard' => $guard,
                'ip' => request()->ip()
            ]);
            
            return [
                'success' => false,
                'message' => trans_message('auth.token_error'),
                'status_code' => 500
            ];
        }
    }

    /**
     * Р’С‹С…РѕРґ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ (РёРЅРІР°Р»РёРґР°С†РёСЏ С‚РѕРєРµРЅР° JWT).
     *
     * @param string $guard
     * @param bool $logAction Р—Р°РїРёСЃС‹РІР°С‚СЊ Р»Рё СЃС‚Р°РЅРґР°СЂС‚РЅРѕРµ СЃРѕР±С‹С‚РёРµ logout РІ Р»РѕРі
     * @return array
     */
    public function logout(string $guard, bool $logAction = true): array
    {
        try {
            Auth::shouldUse($guard);
            
            $user = Auth::user();
            $userId = $user ? $user->id : null;
            $token = JWTAuth::getToken();
            
            if ($token) {
                $authSession = null;
                try {
                    $payload = JWTAuth::setToken($token)->getPayload();
                    $authSession = $this->authSessionService->findActiveByUuid($payload->get('session_uuid'));
                } catch (\Throwable $e) {
                    Log::warning('[JwtAuthService] Failed to resolve auth session during logout', [
                        'user_id' => $userId,
                        'guard' => $guard,
                        'error' => $e->getMessage(),
                    ]);
                }

                if ($authSession) {
                    $this->authSessionService->revoke($authSession, 'logout');
                }

                JWTAuth::invalidate($token);
                Auth::logout(); // true - РѕС‡РёСЃС‚РёС‚СЊ РїРѕР»СЊР·РѕРІР°С‚РµР»СЊСЃРєРёРµ РґР°РЅРЅС‹Рµ
                
                if (request()->hasSession()) {
                    request()->session()->invalidate();
                    request()->session()->regenerateToken();
                }
                
                if ($logAction) { // <-- РџСЂРѕРІРµСЂСЏРµРј С„Р»Р°Рі РїРµСЂРµРґ Р»РѕРіРёСЂРѕРІР°РЅРёРµРј
                    LogService::authLog('logout', [
                        'user_id' => $userId,
                        'guard' => $guard,
                        'ip' => request()->ip(),
                        'token_blacklisted' => true
                    ]);
                }

                return [
                    'success' => true,
                    'message' => 'Р’С‹С…РѕРґ РІС‹РїРѕР»РЅРµРЅ СѓСЃРїРµС€РЅРѕ', // Р­С‚Рѕ СЃРѕРѕР±С‰РµРЅРёРµ РЅРµ Р±СѓРґРµС‚ РІРёРґРЅРѕ РїСЂРё РІС‹Р·РѕРІРµ СЃ Gate::denies
                    'status_code' => 200
                ];
            }
            
            return [
                'success' => false,
                'message' => 'РўРѕРєРµРЅ РЅРµ РЅР°Р№РґРµРЅ',
                'status_code' => 401
            ];
            
        } catch (JWTException $e) {
            LogService::exception($e, [
                'action' => 'logout',
                'guard' => $guard,
                'ip' => request()->ip(),
                'error_message' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'РћС€РёР±РєР° РїСЂРё РІС‹С…РѕРґРµ: ' . $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    /**
     * Р РµРіРёСЃС‚СЂР°С†РёСЏ РЅРѕРІРѕРіРѕ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ.
     *
     * @param RegisterDTO $registerDTO
     * @return array
     */
    public function register(RegisterDTO $registerDTO, ?string $verificationFrontendUrl = null): array
    {
        Log::info('[JwtAuthService] Register method called', [
            'email' => $registerDTO->email ?? 'N/A'
        ]);
        
        DB::beginTransaction(); // РСЃРїРѕР»СЊР·СѓРµРј С‚СЂР°РЅР·Р°РєС†РёСЋ
        try {
            // РџРѕР»СѓС‡Р°РµРј РґР°РЅРЅС‹Рµ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ
            $userData = $registerDTO->getUserData(); // РСЃРїРѕР»СЊР·СѓРµРј getUserData()
            
            Log::info('[JwtAuthService] User data prepared', [
                'email' => $userData['email'] ?? 'N/A',
                'name' => $userData['name'] ?? 'N/A'
            ]);
            
            // РџСЂРѕРІРµСЂСЏРµРј, РЅРµ СЃСѓС‰РµСЃС‚РІСѓРµС‚ Р»Рё СѓР¶Рµ РїРѕР»СЊР·РѕРІР°С‚РµР»СЊ СЃ С‚Р°РєРёРј email
            $existingUser = User::query()
                ->whereRaw('LOWER(email) = ?', [Str::lower((string) $userData['email'])])
                ->first();
            if ($existingUser) {
                Log::warning('[JwtAuthService] User already exists with this email', [
                    'email' => $userData['email'],
                    'user_id' => $existingUser->id
                ]);
                DB::rollBack(); // РѕС‚РєР°С‚С‹РІР°РµРј С‚СЂР°РЅР·Р°РєС†РёСЋ
                return ['success' => false, 'message' => 'РџРѕР»СЊР·РѕРІР°С‚РµР»СЊ СЃ С‚Р°РєРёРј email СѓР¶Рµ СЃСѓС‰РµСЃС‚РІСѓРµС‚', 'status_code' => 422];
            }

            // РЎРѕР·РґР°РµРј РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ
            try {
                $user = $this->userRepository->create($userData);
                Log::info('[JwtAuthService] User created', [
                    'user_id' => $user->id ?? 'Failed to get ID',
                    'email' => $user->email ?? 'N/A'
                ]);
            } catch (\Exception $e) {
                Log::error('[JwtAuthService] Failed to create user', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e; // РџСЂРѕР±СЂР°СЃС‹РІР°РµРј РёСЃРєР»СЋС‡РµРЅРёРµ РґР»СЏ РѕР±СЂР°Р±РѕС‚РєРё РІРѕ РІРЅРµС€РЅРµРј catch
            }
            
            $organization = null;

            // РЎРѕР·РґР°РµРј РѕСЂРіР°РЅРёР·Р°С†РёСЋ, РµСЃР»Рё РёРјСЏ РїРµСЂРµРґР°РЅРѕ
            $orgName = $registerDTO->organizationName; // РСЃРїРѕР»СЊР·СѓРµРј РјР°РіРёС‡РµСЃРєРёР№ __get
            Log::info('[JwtAuthService] Organization name from DTO', [
                'organization_name' => $orgName
            ]);
            
            if (!empty($orgName)) {
                // РџРѕР»СѓС‡Р°РµРј РґР°РЅРЅС‹Рµ РѕСЂРіР°РЅРёР·Р°С†РёРё РёР· DTO
                $orgData = $registerDTO->getOrganizationData();
                
                // Р”РѕР±Р°РІР»СЏРµРј owner_id
                $orgData['owner_id'] = $user->id;
                
                Log::info('[JwtAuthService] Organization data prepared', [
                    'org_name' => $orgData['name'],
                    'owner_id' => $orgData['owner_id'],
                    'legal_name' => $orgData['legal_name'] ?? 'РЅРµ СѓРєР°Р·Р°РЅРѕ',
                    'tax_number' => $orgData['tax_number'] ?? 'РЅРµ СѓРєР°Р·Р°РЅРѕ',
                    'address' => $orgData['address'] ?? 'РЅРµ СѓРєР°Р·Р°РЅРѕ'
                ]);
                
                try {
                    if (!empty($orgData['tax_number'])) {
                        $existingOrg = Organization::where('tax_number', $orgData['tax_number'])->first();
                        if ($existingOrg) {
                            Log::warning('[JwtAuthService] Organization with this INN already exists', [
                                'tax_number' => $orgData['tax_number'],
                                'existing_org_id' => $existingOrg->id,
                                'existing_org_name' => $existingOrg->name
                            ]);
                            
                            DB::rollBack();
                            
                            return [
                                'success' => false, 
                                'message' => 'РћСЂРіР°РЅРёР·Р°С†РёСЏ СЃ С‚Р°РєРёРј РРќРќ СѓР¶Рµ Р·Р°СЂРµРіРёСЃС‚СЂРёСЂРѕРІР°РЅР° РІ СЃРёСЃС‚РµРјРµ. Р•СЃР»Рё РІС‹ СЏРІР»СЏРµС‚РµСЃСЊ СЃРѕС‚СЂСѓРґРЅРёРєРѕРј СЌС‚РѕР№ РѕСЂРіР°РЅРёР·Р°С†РёРё, РїРѕРїСЂРѕСЃРёС‚Рµ РІР»Р°РґРµР»СЊС†Р° РґРѕР±Р°РІРёС‚СЊ РІР°СЃ РІ РєРѕРјР°РЅРґСѓ.', 
                                'status_code' => 422
                            ];
                        }
                    }
                    
                    $organization = $this->organizationRepository->create($orgData);
                    Log::info('[JwtAuthService] Organization created', [
                        'org_id' => $organization->id ?? 'Failed to get ID',
                        'name' => $organization->name,
                        'tax_number' => $organization->tax_number
                    ]);
                    
                    if (!$user->organizations()->where('organization_id', $organization->id)->exists()) {
                        $user->organizations()->attach($organization->id, [
                            'is_owner' => true,
                            'is_active' => true
                        ]);
                    }
                    $user->current_organization_id = $organization->id;
                    $user->save();
                    Log::info('[JwtAuthService] Set current organization for user', [
                        'user_id' => $user->id,
                        'current_org_id' => $organization->id
                    ]);

                    $this->userRepository->assignRoleToUser($user->id, 'organization_owner', $organization->id);
                    Log::info('[JwtAuthService] Owner role assigned to user after registration (new auth system)', [
                        'user_id' => $user->id,
                        'organization_id' => $organization->id,
                        'role_slug' => 'organization_owner'
                    ]);

                    $this->grantTestingBalanceIfEnabled($organization, $user);

                } catch (\Illuminate\Database\QueryException $e) {
                    if (str_contains($e->getMessage(), 'organizations_tax_number_unique') || 
                        str_contains($e->getMessage(), 'duplicate key')) {
                        Log::warning('[JwtAuthService] Duplicate INN detected in database', [
                            'tax_number' => $orgData['tax_number'] ?? 'unknown',
                            'error' => $e->getMessage()
                        ]);
                        
                        DB::rollBack();
                        
                        return [
                            'success' => false, 
                            'message' => 'РћСЂРіР°РЅРёР·Р°С†РёСЏ СЃ С‚Р°РєРёРј РРќРќ СѓР¶Рµ Р·Р°СЂРµРіРёСЃС‚СЂРёСЂРѕРІР°РЅР° РІ СЃРёСЃС‚РµРјРµ. Р•СЃР»Рё РІС‹ СЏРІР»СЏРµС‚РµСЃСЊ СЃРѕС‚СЂСѓРґРЅРёРєРѕРј СЌС‚РѕР№ РѕСЂРіР°РЅРёР·Р°С†РёРё, РїРѕРїСЂРѕСЃРёС‚Рµ РІР»Р°РґРµР»СЊС†Р° РґРѕР±Р°РІРёС‚СЊ РІР°СЃ РІ РєРѕРјР°РЅРґСѓ.', 
                            'status_code' => 422
                        ];
                    }
                    
                    Log::error('[JwtAuthService] Failed to create organization', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    throw $e;
                } catch (\Exception $e) {
                    Log::error('[JwtAuthService] Failed to create organization', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    throw $e;
                }
            }

            // Р“РµРЅРµСЂРёСЂСѓРµРј JWT С‚РѕРєРµРЅ РґР»СЏ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ
            $token = null;
            try {
                if ($organization) {
                    $customClaims = ['organization_id' => $organization->id];
                    if ((bool) config('auth_tokens.sessions.enabled', true)) {
                        $authSession = $this->authSessionService->createForLogin(
                            $user,
                            (int) $organization->id,
                            request()
                        );
                        $customClaims['session_uuid'] = $authSession->session_uuid;
                    }
                    $token = JWTAuth::claims($customClaims)->fromUser($user);
                } else {
                    $customClaims = [];
                    if ((bool) config('auth_tokens.sessions.enabled', true)) {
                        $authSession = $this->authSessionService->createForLogin($user, null, request());
                        $customClaims['session_uuid'] = $authSession->session_uuid;
                    }
                    $token = $customClaims
                        ? JWTAuth::claims($customClaims)->fromUser($user)
                        : JWTAuth::fromUser($user);
                }
                Log::info('[JwtAuthService] JWT token generated');
            } catch (\Exception $e) {
                Log::error('[JwtAuthService] Failed to generate JWT token', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }

            // Р¤РёРєСЃРёСЂСѓРµРј С‚СЂР°РЅР·Р°РєС†РёСЋ
            DB::commit();

            if ($organization) {
                try {
                    $processedInvitations = app(\App\Services\Project\ProjectParticipantInvitationService::class)
                        ->acceptMatchingForOrganization($user, $organization);

                    Log::info('[JwtAuthService] Project participant invitations processed after registration', [
                        'user_id' => $user->id,
                        'organization_id' => $organization->id,
                        'invitation_stats' => $processedInvitations,
                    ]);
                } catch (\Exception $invitationException) {
                    Log::warning('[JwtAuthService] Failed to process project participant invitations after registration', [
                        'user_id' => $user->id,
                        'organization_id' => $organization->id,
                        'error' => $invitationException->getMessage(),
                    ]);
                }
            }

            // РђР’РўРћРњРђРўРР§Р•РЎРљРђРЇ Р’Р•Р РР¤РРљРђР¦РРЇ Р РЎРРќРҐР РћРќРР—РђР¦РРЇ (РІРЅРµ С‚СЂР°РЅР·Р°РєС†РёРё)
            if ($organization && !empty($organization->tax_number)) {
                try {
                    $autoVerificationService = app(\App\Services\Security\ContractorAutoVerificationService::class);
                    $verificationResult = $autoVerificationService->verifyAndSetAccess($organization);
                    
                    Log::info('[JwtAuthService] Auto-verification completed', [
                        'organization_id' => $organization->id,
                        'verification_score' => $verificationResult['verification_score'],
                        'access_level' => $verificationResult['access_level']
                    ]);
                    
                    $syncService = app(\App\Services\Contractor\ContractorSyncService::class);
                    
                    // РЎРёРЅС…СЂРѕРЅРёР·РёСЂСѓРµРј С‚РѕР»СЊРєРѕ РЅРµ СЃРёРЅС…СЂРѕРЅРёР·РёСЂРѕРІР°РЅРЅС‹С… РїРѕРґСЂСЏРґС‡РёРєРѕРІ
                    $unsyncedContractors = $syncService->findContractorsByInn($organization->tax_number, true);
                    
                    if ($unsyncedContractors->isNotEmpty()) {
                        $syncResult = $syncService->syncContractorWithOrganization($organization);
                        
                        Log::info('[JwtAuthService] Contractor synchronization completed', [
                            'organization_id' => $organization->id,
                            'tax_number' => $organization->tax_number,
                            'contractors_synced' => $syncResult['contractors'],
                            'projects_synced' => $syncResult['projects']
                        ]);
                    }
                    
                    // Р”Р»СЏ СѓРІРµРґРѕРјР»РµРЅРёР№ РёС‰РµРј Р’РЎР•РҐ РїРѕРґСЂСЏРґС‡РёРєРѕРІ СЃ С‚Р°РєРёРј РРќРќ (РІРєР»СЋС‡Р°СЏ СѓР¶Рµ СЃРёРЅС…СЂРѕРЅРёР·РёСЂРѕРІР°РЅРЅС‹С…)
                    $allContractorsByInn = $syncService->findContractorsByInn($organization->tax_number, false);
                    
                    Log::info('[JwtAuthService] All contractors search by INN for notifications', [
                        'organization_id' => $organization->id,
                        'tax_number' => $organization->tax_number,
                        'contractors_found' => $allContractorsByInn->count(),
                        'contractors' => $allContractorsByInn->pluck('id', 'name')->toArray()
                    ]);
                    
                    if ($allContractorsByInn->isNotEmpty()) {
                        Log::info('[JwtAuthService] Starting critical customer notifications', [
                            'organization_id' => $organization->id,
                            'organization_name' => $organization->name,
                            'tax_number' => $organization->tax_number,
                            'contractors_count' => $allContractorsByInn->count(),
                            'contractors_details' => $allContractorsByInn->map(function($c) {
                                return [
                                    'id' => $c->id,
                                    'name' => $c->name,
                                    'customer_org_id' => $c->organization_id
                                ];
                            })->toArray()
                        ]);
                        
                        try {
                            $notificationService = app(\App\Services\Security\ContractorRegistrationNotificationService::class);
                            $notificationService->notifyCustomersAboutRegistration(
                                $organization,
                                $allContractorsByInn,
                                $verificationResult
                            );
                            
                            Log::channel('security')->info('[JwtAuthService] вњ… Customer notifications SUCCESSFULLY sent', [
                                'organization_id' => $organization->id,
                                'organization_name' => $organization->name,
                                'customers_notified' => $allContractorsByInn->count(),
                                'verification_score' => $verificationResult['verification_score']
                            ]);
                        } catch (\Exception $notifEx) {
                            Log::channel('security')->critical('[JwtAuthService] вќЊ CRITICAL: Failed to send customer notifications', [
                                'organization_id' => $organization->id,
                                'organization_name' => $organization->name,
                                'tax_number' => $organization->tax_number,
                                'contractors_count' => $allContractorsByInn->count(),
                                'error' => $notifEx->getMessage(),
                                'trace' => $notifEx->getTraceAsString()
                            ]);
                            // РќР• РїСЂРµСЂС‹РІР°РµРј СЂРµРіРёСЃС‚СЂР°С†РёСЋ, РЅРѕ Р·Р°РїРёСЃС‹РІР°РµРј РєСЂРёС‚РёС‡РµСЃРєСѓСЋ РѕС€РёР±РєСѓ
                        }
                    } else {
                        Log::info('[JwtAuthService] No existing contractors found for INN (no notifications to send)', [
                            'organization_id' => $organization->id,
                            'tax_number' => $organization->tax_number
                        ]);
                    }
                } catch (\Exception $syncException) {
                    Log::warning('[JwtAuthService] Verification/sync process failed (non-critical)', [
                        'organization_id' => $organization->id,
                        'tax_number' => $organization->tax_number,
                        'error' => $syncException->getMessage(),
                        'trace' => $syncException->getTraceAsString()
                    ]);
                    // РќРµ РїСЂРµСЂС‹РІР°РµРј СЂРµРіРёСЃС‚СЂР°С†РёСЋ - РІРµСЂРёС„РёРєР°С†РёСЏ РЅРµ РєСЂРёС‚РёС‡РЅР°
                }
            }

            // РћС‚РїСЂР°РІР»СЏРµРј РїРёСЃСЊРјРѕ РґР»СЏ РІРµСЂРёС„РёРєР°С†РёРё email
            try {
                if ($verificationFrontendUrl) {
                    $user->sendFrontendEmailVerificationNotification($verificationFrontendUrl);
                } else {
                    $user->sendEmailVerificationNotification();
                }
                Log::info('[JwtAuthService] Email verification notification sent', [
                    'user_id' => $user->id,
                    'email' => $user->email
                ]);
            } catch (\Throwable $mailEx) {
                Log::error('[JwtAuthService] Failed to send email verification notification', [
                    'user_id' => $user->id,
                    'error' => $mailEx->getMessage(),
                ]);
            }

            // Р’РµСЂРёС„РёС†РёСЂСѓРµРј, С‡С‚Рѕ РїРѕР»СЊР·РѕРІР°С‚РµР»СЊ РґРµР№СЃС‚РІРёС‚РµР»СЊРЅРѕ СЃРѕС…СЂР°РЅРµРЅ
            $checkUser = $this->userRepository->findByEmail($userData['email']);
            if (!$checkUser) {
                Log::critical('[JwtAuthService] User not found after successful registration!', [
                    'email' => $userData['email']
                ]);
            } else {
                Log::info('[JwtAuthService] User verified after registration', [
                    'user_id' => $checkUser->id,
                    'email' => $checkUser->email
                ]);
            }

            LogService::authLog('register_success', [
                'user_id' => $user->id, 
                'email' => $user->email,
                'organization_id' => $organization ? $organization->id : null
            ]);
            
            return [
                'success' => true, 
                'user' => $user, 
                'organization' => $organization,
                'token' => $token,
                'status_code' => 201
            ];

        } catch (\Exception $e) {
            DB::rollBack(); // РћС‚РєР°С‚С‹РІР°РµРј С‚СЂР°РЅР·Р°РєС†РёСЋ
            Log::error('[JwtAuthService] Register exception', [
                'error' => $e->getMessage(),
                'type' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'email' => $registerDTO->email ?? 'N/A'
            ]);
            
            LogService::exception($e, [
                'action' => 'register', 
                'email' => $registerDTO->email ?? 'N/A'
            ]);
            
            return [
                'success' => false, 
                'message' => 'РћС€РёР±РєР° РїСЂРё СЂРµРіРёСЃС‚СЂР°С†РёРё РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ: ' . $e->getMessage(), 
                'status_code' => 500
            ];
        }
    }

    /**
     * Р’С‹РґР°С‡Р° С‚РµСЃС‚РѕРІРѕРіРѕ Р±Р°Р»Р°РЅСЃР° РїСЂРё СЂРµРіРёСЃС‚СЂР°С†РёРё (РµСЃР»Рё РІРєР»СЋС‡РµРЅ С‚РµСЃС‚РѕРІС‹Р№ СЂРµР¶РёРј)
     * 
     * @param \App\Models\Organization $organization
     * @param User $user
     * @return void
     */
    protected function grantTestingBalanceIfEnabled(\App\Models\Organization $organization, User $user): void
    {
        // РџСЂРѕРІРµСЂСЏРµРј, РІРєР»СЋС‡РµРЅ Р»Рё С‚РµСЃС‚РѕРІС‹Р№ СЂРµР¶РёРј
        if (!config('billing.testing.enabled', false)) {
            Log::debug('[JwtAuthService] Testing mode is disabled, skipping initial balance grant', [
                'organization_id' => $organization->id,
            ]);
            return;
        }

        try {
            $initialBalance = config('billing.testing.initial_balance', 0);
            $description = config('billing.testing.description', 'РўРµСЃС‚РѕРІС‹Р№ Р±Р°Р»Р°РЅСЃ РїСЂРё СЂРµРіРёСЃС‚СЂР°С†РёРё');
            $meta = array_merge(
                config('billing.testing.meta', []),
                [
                    'granted_at_registration' => true,
                    'user_id' => $user->id,
                    'environment' => app()->environment(),
                ]
            );

            if ($initialBalance <= 0) {
                Log::warning('[JwtAuthService] Testing mode enabled but initial balance is 0 or negative', [
                    'organization_id' => $organization->id,
                    'initial_balance' => $initialBalance,
                ]);
                return;
            }

            // Р’С‹РґР°РµРј Р±Р°Р»Р°РЅСЃ С‡РµСЂРµР· BalanceService
            $orgBalance = $this->balanceService->creditBalance(
                $organization,
                $initialBalance,
                $description,
                null, // payment = null (СЌС‚Рѕ РЅРµ РїРѕРїРѕР»РЅРµРЅРёРµ С‡РµСЂРµР· РїР»Р°С‚РµР¶)
                $meta
            );

            Log::channel('business')->info('billing.testing.initial_balance_granted', [
                'organization_id' => $organization->id,
                'organization_name' => $organization->name,
                'user_id' => $user->id,
                'user_email' => $user->email,
                'amount_cents' => $initialBalance,
                'amount_rubles' => round($initialBalance / 100, 2),
                'balance_after_cents' => $orgBalance->balance,
                'balance_after_rubles' => round($orgBalance->balance / 100, 2),
                'testing_mode' => true,
                'environment' => app()->environment(),
            ]);

            Log::info('[JwtAuthService] вњ… Testing balance granted successfully', [
                'organization_id' => $organization->id,
                'amount_rubles' => round($initialBalance / 100, 2),
                'balance_after_rubles' => round($orgBalance->balance / 100, 2),
            ]);

        } catch (\Exception $e) {
            // РќРµ РїСЂРµСЂС‹РІР°РµРј СЂРµРіРёСЃС‚СЂР°С†РёСЋ, РµСЃР»Рё РЅРµ СѓРґР°Р»РѕСЃСЊ РІС‹РґР°С‚СЊ С‚РµСЃС‚РѕРІС‹Р№ Р±Р°Р»Р°РЅСЃ
            Log::error('[JwtAuthService] Failed to grant testing balance', [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
} 
