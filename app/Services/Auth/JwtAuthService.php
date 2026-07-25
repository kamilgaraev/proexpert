<?php

namespace App\Services\Auth;

use App\DTOs\Auth\LoginDTO;
use App\DTOs\Auth\RegisterDTO;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Models\Organization;
use App\Notifications\LandingResetPasswordNotification;
use App\Repositories\Interfaces\OrganizationRepositoryInterface;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\Services\LogService;
use App\Services\PerformanceMonitor;
use App\Services\Auth\UserAuthSessionService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tymon\JWTAuth\JWT;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\UserWelcomeMail;

use function trans_message;

class JwtAuthService
{
    protected UserRepositoryInterface $userRepository;
    protected OrganizationRepositoryInterface $organizationRepository;
    protected UserAuthSessionService $authSessionService;
    protected JwtTokenIssuer $tokenIssuer;
    protected PasswordResetService $passwordResetService;

    /**
     * Конструктор сервиса аутентификации.
     *
     * @param UserRepositoryInterface $userRepository
     * @param OrganizationRepositoryInterface $organizationRepository
     */
    public function __construct(
        UserRepositoryInterface $userRepository,
        OrganizationRepositoryInterface $organizationRepository,
        UserAuthSessionService $authSessionService,
        JwtTokenIssuer $tokenIssuer,
        PasswordResetService $passwordResetService,
        private readonly JWT $jwt,
    ) {
        $this->userRepository = $userRepository;
        $this->organizationRepository = $organizationRepository;
        $this->authSessionService = $authSessionService;
        $this->tokenIssuer = $tokenIssuer;
        $this->passwordResetService = $passwordResetService;
    }

    /**
     * Аутентификация пользователя и получение токена JWT.
     *
     * @param LoginDTO $loginDTO
     * @param string $guard
     * @return array
     */
    public function authenticate(LoginDTO $loginDTO, string $guard): array
    {
        try {
            Auth::shouldUse($guard);

            if (! Auth::validate($loginDTO->toArray())) {
                return [
                    'success' => false,
                    'message' => trans_message('auth.login_failed'),
                    'status_code' => 401,
                ];
            }

            $authenticatedUser = Auth::getLastAttempted();

            if (! $authenticatedUser instanceof User) {
                throw new \RuntimeException('Authenticated principal is not a user.');
            }

            return DB::transaction(function () use ($authenticatedUser, $loginDTO, $guard): array {
                $user = User::query()
                    ->whereKey($authenticatedUser->id)
                    ->lockForUpdate()
                    ->first();

                if (! $user instanceof User
                    || ! hash_equals(Str::lower($user->email), $loginDTO->getEmail())
                    || ! Hash::check($loginDTO->getPassword(), $user->password)
                ) {
                    return [
                        'success' => false,
                        'message' => trans_message('auth.login_failed'),
                        'status_code' => 401,
                    ];
                }

                if (! $user->is_active) {
                    return [
                        'success' => false,
                        'message' => trans_message('auth.account_disabled'),
                        'status_code' => 403,
                    ];
                }

                if (! $user->hasVerifiedEmail()) {
                    return [
                        'success' => false,
                        'message' => trans_message('auth.email_verification_required'),
                        'status_code' => 403,
                    ];
                }

                $user->update([
                    'last_login_at' => now(),
                    'last_login_ip' => request()->ip(),
                ]);

                $assignmentsCount = 0;

                try {
                    $user->load('roleAssignments');
                    $assignmentsCount = $user->roleAssignments->count();
                } catch (\Throwable $exception) {
                    Log::warning('auth.role_assignments_unavailable', [
                        'user_id' => $user->id,
                        'exception_class' => $exception::class,
                    ]);
                }

                if ($assignmentsCount === 0) {
                    $ownerOrganization = $user->organizations()
                        ->wherePivot('is_owner', true)
                        ->wherePivot('is_active', true)
                        ->first();

                    if ($ownerOrganization !== null) {
                        try {
                            $this->userRepository->assignRoleToUser($user->id, 'organization_owner', $ownerOrganization->id);
                        } catch (\Throwable $exception) {
                            Log::warning('auth.owner_role_assignment_failed', [
                                'user_id' => $user->id,
                                'organization_id' => $ownerOrganization->id,
                                'exception_class' => $exception::class,
                            ]);
                        }
                    }
                }

                $organizationId = $this->resolveLoginOrganizationId($user, $guard);

                if ($organizationId !== null && $user->current_organization_id !== $organizationId) {
                    $user->current_organization_id = $organizationId;
                    $user->save();
                }

                $token = $this->tokenIssuer->issue($user, [
                    'guard' => $guard,
                    'organization_id' => $organizationId,
                    'request' => request(),
                ]);

                return [
                    'success' => true,
                    'token' => $token,
                    'user' => $user,
                    'status_code' => 200,
                ];
            });
        } catch (JWTException $exception) {
            Log::error('auth.token_issue_failed', [
                'guard' => $guard,
                'exception_class' => $exception::class,
            ]);

            return [
                'success' => false,
                'message' => trans_message('auth.jwt_creation_error'),
                'status_code' => 500,
            ];
        } catch (\Throwable $exception) {
            Log::error('auth.login_failed_unexpectedly', [
                'guard' => $guard,
                'exception_class' => $exception::class,
            ]);

            return [
                'success' => false,
                'message' => trans_message('auth.login_internal_error'),
                'status_code' => 500,
            ];
        }
    }

    /**
     * Получение информации о текущем пользователе.
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
                    'message' => trans_message('auth.not_authenticated'),
                    'status_code' => 401
                ];
            }

            // Загружаем дополнительные данные с кэшированием
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
                'message' => trans_message('auth.token_expired'),
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
                'message' => trans_message('auth.token_invalid'),
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
                'message' => trans_message('auth.token_missing'),
                'status_code' => 401
            ];
        }
    }

    /**
     * Получение ID текущей организации из JWT токена.
     *
     * @return int|null ID организации или null, если токен не содержит organization_id.
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
        $user = $this->passwordResetService->reset($payload);

        if (! $user instanceof User) {
            return [
                'success' => false,
                'status_code' => 422,
                'message' => trans_message('auth.password_reset.invalid'),
            ];
        }

        try {
            event(new PasswordReset($user));
        } catch (\Throwable $exception) {
            Log::warning('auth.password_reset_event_failed', [
                'user_id' => $user->id,
                'exception_class' => $exception::class,
            ]);
        }

        return [
            'success' => true,
            'status_code' => 200,
        ];
    }

    public function getCurrentOrganizationId(): ?int
    {
        try {
            $payload = $this->jwt->parseToken()->getPayload();
            // Предполагаем, что ID организации хранится в claim 'organization_id'
            return $payload->get('organization_id');
        } catch (JWTException $e) {
            // Обработка случаев, когда токен невалиден, отсутствует или не содержит нужного claim
            LogService::exception($e, [
                'action' => 'get_current_organization_id',
                'ip' => request()->ip()
            ]);
            return null;
        }
    }

    /**
     * Обновление токена JWT.
     *
     * @param string $guard
     * @return array
     */
    public function refresh(string $guard): array
    {
        try {
            Auth::shouldUse($guard);
            $payload = request()->attributes->get('token_payload') ?? $this->jwt->parseToken()->getPayload();
            $claims = array_filter([
                'organization_id' => $payload->get('organization_id'),
                'session_uuid' => $payload->get('session_uuid'),
            ], static fn ($value) => $value !== null);
            $token = auth($guard)->claims($claims)->refresh();
            
            // Получаем пользователя после обновления токена
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
     * Выход пользователя (инвалидация токена JWT).
     *
     * @param string $guard
     * @param bool $logAction Записывать ли стандартное событие logout в лог
     * @return array
     */
    public function logout(string $guard, bool $logAction = true): array
    {
        try {
            Auth::shouldUse($guard);
            
            $user = Auth::user();
            $userId = $user ? $user->id : null;
            $token = $this->jwt->getToken();
            
            if ($token) {
                $authSession = null;
                try {
                    $payload = $this->jwt->setToken($token)->getPayload();
                    $authSession = $this->authSessionService->findActiveByUuid($payload->get('session_uuid'));
                } catch (\Throwable $e) {
                    Log::warning('auth.logout_session_resolution_failed', [
                        'user_id' => $userId,
                        'guard' => $guard,
                        'exception_class' => $e::class,
                    ]);
                }

                if ($authSession) {
                    $this->authSessionService->revoke($authSession, 'logout');
                }

                $this->jwt->invalidate($token);
                Auth::logout(); // true - очистить пользовательские данные
                
                if (request()->hasSession()) {
                    request()->session()->invalidate();
                    request()->session()->regenerateToken();
                }
                
                if ($logAction) { // <-- Проверяем флаг перед логированием
                    LogService::authLog('logout', [
                        'user_id' => $userId,
                        'guard' => $guard,
                        'ip' => request()->ip(),
                        'token_blacklisted' => true
                    ]);
                }

                return [
                    'success' => true,
                    'message' => trans_message('auth.logout_success'),
                    'status_code' => 200
                ];
            }
            
            return [
                'success' => false,
                'message' => trans_message('auth.logout_token_missing'),
                'status_code' => 401
            ];
            
        } catch (JWTException $e) {
            Log::warning('auth.logout_failed', [
                'guard' => $guard,
                'exception_class' => $e::class,
            ]);
            
            return [
                'success' => false,
                'message' => trans_message('auth.logout_error'),
                'status_code' => 500
            ];
        }
    }

    /**
     * Регистрация нового пользователя.
     *
     * @param RegisterDTO $registerDTO
     * @return array
     */
    public function register(RegisterDTO $registerDTO, ?string $verificationFrontendUrl = null): array
    {
        DB::beginTransaction(); // Используем транзакцию
        try {
            // Получаем данные пользователя
            $userData = $registerDTO->getUserData(); // Используем getUserData()
            
            // Проверяем, не существует ли уже пользователь с таким email
            $existingUser = User::query()
                ->whereRaw('LOWER(email) = ?', [Str::lower((string) $userData['email'])])
                ->first();
            if ($existingUser) {
                Log::warning('auth.registration_duplicate_user', [
                    'user_id' => $existingUser->id
                ]);
                DB::rollBack(); // откатываем транзакцию
                return ['success' => false, 'message' => trans_message('auth.registration_user_exists'), 'status_code' => 422];
            }

            // Создаем пользователя
            try {
                $user = $this->userRepository->create($userData);
                Log::info('auth.registration_user_created', [
                    'user_id' => $user->id,
                ]);
            } catch (QueryException $e) {
                if ($this->isEmailUniqueViolation($e)) {
                    Log::warning('auth.registration_duplicate_user', [
                        'exception_class' => $e::class,
                    ]);

                    DB::rollBack();

                    return [
                        'success' => false,
                        'message' => trans_message('auth.registration_user_exists'),
                        'status_code' => 422,
                    ];
                }

                Log::error('auth.registration_user_creation_failed', [
                    'exception_class' => $e::class,
                ]);
                throw $e;
            } catch (\Exception $e) {
                Log::error('auth.registration_user_creation_failed', [
                    'exception_class' => $e::class,
                ]);
                throw $e; // Пробрасываем исключение для обработки во внешнем catch
            }
            
            $organization = null;

            // Создаем организацию, если имя передано
            $orgName = $registerDTO->organizationName; // Используем магический __get
            
            if (!empty($orgName)) {
                // Получаем данные организации из DTO
                $orgData = $registerDTO->getOrganizationData();
                
                // Добавляем owner_id
                $orgData['owner_id'] = $user->id;
                
                try {
                    if (!empty($orgData['tax_number'])) {
                        $existingOrg = Organization::where('tax_number', $orgData['tax_number'])->first();
                        if ($existingOrg) {
                            Log::warning('auth.registration_duplicate_organization', [
                                'existing_org_id' => $existingOrg->id,
                            ]);
                            
                            DB::rollBack();
                            
                            return [
                                'success' => false, 
                                'message' => trans_message('auth.registration_organization_tax_number_exists'),
                                'status_code' => 422
                            ];
                        }
                    }
                    
                    $organization = $this->organizationRepository->create($orgData);
                    Log::info('auth.registration_organization_created', [
                        'organization_id' => $organization->id,
                    ]);
                    
                    if (!$user->organizations()->where('organization_id', $organization->id)->exists()) {
                        $user->organizations()->attach($organization->id, [
                            'is_owner' => true,
                            'is_active' => true
                        ]);
                    }
                    $user->current_organization_id = $organization->id;
                    $user->save();
                    Log::info('auth.registration_organization_selected', [
                        'user_id' => $user->id,
                        'organization_id' => $organization->id,
                    ]);

                    $this->userRepository->assignRoleToUser($user->id, 'organization_owner', $organization->id);
                    Log::info('auth.registration_owner_role_assigned', [
                        'user_id' => $user->id,
                        'organization_id' => $organization->id,
                    ]);

                } catch (\Illuminate\Database\QueryException $e) {
                    if (str_contains($e->getMessage(), 'organizations_tax_number_unique') || 
                        str_contains($e->getMessage(), 'duplicate key')) {
                        Log::warning('auth.registration_duplicate_organization', [
                            'exception_class' => $e::class,
                        ]);
                        
                        DB::rollBack();
                        
                        return [
                            'success' => false, 
                            'message' => trans_message('auth.registration_organization_tax_number_exists'),
                            'status_code' => 422
                        ];
                    }
                    
                    Log::error('auth.registration_organization_creation_failed', [
                        'user_id' => $user->id,
                        'exception_class' => $e::class,
                    ]);
                    throw $e;
                } catch (\Exception $e) {
                    Log::error('auth.registration_organization_creation_failed', [
                        'user_id' => $user->id,
                        'exception_class' => $e::class,
                    ]);
                    throw $e;
                }
            }

            // Фиксируем транзакцию
            DB::commit();

            if ($organization) {
                try {
                    $processedInvitations = app(\App\Services\Project\ProjectParticipantInvitationService::class)
                        ->acceptMatchingForOrganization($user, $organization);

                    Log::info('[JwtAuthService] Project participant invitations processed after registration', [
                        'user_id' => $user->id,
                        'organization_id' => $organization->id,
                    ]);
                } catch (\Exception $invitationException) {
                    Log::warning('auth.registration_invitation_processing_failed', [
                        'user_id' => $user->id,
                        'organization_id' => $organization->id,
                        'exception_class' => $invitationException::class,
                    ]);
                }
            }

            // АВТОМАТИЧЕСКАЯ ВЕРИФИКАЦИЯ И СИНХРОНИЗАЦИЯ (вне транзакции)
            if ($organization && !empty($organization->tax_number)) {
                try {
                    $autoVerificationService = app(\App\Services\Security\ContractorAutoVerificationService::class);
                    $verificationResult = $autoVerificationService->verifyAndSetAccess($organization);
                    
                    Log::info('auth.registration_auto_verification_completed', [
                        'organization_id' => $organization->id,
                        'verification_score' => $verificationResult['verification_score'],
                        'access_level' => $verificationResult['access_level']
                    ]);
                    
                    $syncService = app(\App\Services\Contractor\ContractorSyncService::class);
                    
                    // Синхронизируем только не синхронизированных подрядчиков
                    $unsyncedContractors = $syncService->findContractorsByInn($organization->tax_number, true);
                    
                    if ($unsyncedContractors->isNotEmpty()) {
                        $syncResult = $syncService->syncContractorWithOrganization($organization);
                        
                        Log::info('auth.registration_contractor_synchronization_completed', [
                            'organization_id' => $organization->id,
                            'contractors_synced' => $syncResult['contractors'],
                            'projects_synced' => $syncResult['projects']
                        ]);
                    }
                    
                    // Для уведомлений ищем ВСЕХ подрядчиков с таким ИНН (включая уже синхронизированных)
                    $allContractorsByInn = $syncService->findContractorsByInn($organization->tax_number, false);
                    
                    Log::info('auth.registration_contractors_resolved', [
                        'organization_id' => $organization->id,
                        'contractors_found' => $allContractorsByInn->count(),
                    ]);
                    
                    if ($allContractorsByInn->isNotEmpty()) {
                        Log::info('auth.registration_customer_notifications_started', [
                            'organization_id' => $organization->id,
                            'contractors_count' => $allContractorsByInn->count(),
                        ]);
                        
                        try {
                            $notificationService = app(\App\Services\Security\ContractorRegistrationNotificationService::class);
                            $notificationService->notifyCustomersAboutRegistration(
                                $organization,
                                $allContractorsByInn,
                                $verificationResult
                            );
                            
                            Log::channel('security')->info('auth.registration_customer_notifications_completed', [
                                'organization_id' => $organization->id,
                                'customers_notified' => $allContractorsByInn->count(),
                                'verification_score' => $verificationResult['verification_score']
                            ]);
                        } catch (\Exception $notifEx) {
                            Log::channel('security')->critical('auth.registration_customer_notifications_failed', [
                                'organization_id' => $organization->id,
                                'contractors_count' => $allContractorsByInn->count(),
                                'exception_class' => $notifEx::class,
                            ]);
                            // НЕ прерываем регистрацию, но записываем критическую ошибку
                        }
                    } else {
                        Log::info('auth.registration_contractors_not_found', [
                            'organization_id' => $organization->id,
                        ]);
                    }
                } catch (\Exception $syncException) {
                    Log::warning('auth.registration_verification_sync_failed', [
                        'organization_id' => $organization->id,
                        'exception_class' => $syncException::class,
                    ]);
                    // Не прерываем регистрацию - верификация не критична
                }
            }

            // Отправляем письмо для верификации email
            try {
                if ($verificationFrontendUrl) {
                    $user->sendFrontendEmailVerificationNotification($verificationFrontendUrl);
                } else {
                    $user->sendEmailVerificationNotification();
                }
                Log::info('auth.registration_email_verification_sent', [
                    'user_id' => $user->id,
                ]);
            } catch (\Throwable $mailEx) {
                Log::error('auth.registration_email_verification_failed', [
                    'user_id' => $user->id,
                    'exception_class' => $mailEx::class,
                ]);
            }

            // Верифицируем, что пользователь действительно сохранен
            $checkUser = $this->userRepository->findByEmail($userData['email']);
            if (!$checkUser) {
                Log::critical('auth.registration_user_persistence_failed', [
                    'user_id' => $user->id,
                ]);
            } else {
                Log::info('auth.registration_user_persistence_verified', [
                    'user_id' => $checkUser->id,
                ]);
            }

            LogService::authLog('register_success', [
                'user_id' => $user->id, 
                'organization_id' => $organization ? $organization->id : null
            ]);
            
            return [
                'success' => true, 
                'user' => $user, 
                'organization' => $organization,
                'status' => 'verification_required',
                'email_verified' => false,
                'can_enter_portal' => false,
                'status_code' => 201
            ];

        } catch (\Exception $e) {
            DB::rollBack(); // Откатываем транзакцию
            Log::error('auth.registration_failed', [
                'exception_class' => $e::class,
            ]);
            
            return [
                'success' => false, 
                'message' => trans_message('auth.registration_error'),
                'status_code' => 500
            ];
        }
    }

    private function isEmailUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $message = $exception->getMessage();

        return in_array($sqlState, ['23505', '23000'], true)
            && (
                str_contains($message, 'users_email_unique')
                || str_contains($message, 'users_email_lower_unique')
                || str_contains($message, 'users_email_lower_active_unique')
                || str_contains($message, 'users.email')
            );
    }

    private function resolveLoginOrganizationId(User $user, string $guard): ?int
    {
        $activeOrganizationIds = $user->organizations()
            ->wherePivot('is_active', true)
            ->orderByDesc('organization_user.is_owner')
            ->orderBy('organizations.id')
            ->pluck('organizations.id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        if ($activeOrganizationIds === []) {
            return null;
        }

        $currentOrganizationId = $user->current_organization_id
            ? (int) $user->current_organization_id
            : null;
        $currentOrganizationIsActive = $currentOrganizationId !== null
            && in_array($currentOrganizationId, $activeOrganizationIds, true);

        if ($guard !== 'api_admin') {
            return $currentOrganizationIsActive ? $currentOrganizationId : $activeOrganizationIds[0];
        }

        $hasSystemAdminAccess = $this->userCanAccessAdminSystem($user);

        if (
            $currentOrganizationIsActive
            && (
                $hasSystemAdminAccess
                || $this->userCanAccessAdminOrganization($user, $currentOrganizationId)
            )
        ) {
            return $currentOrganizationId;
        }

        foreach ($activeOrganizationIds as $organizationId) {
            if ($this->userCanAccessAdminOrganization($user, $organizationId)) {
                return $organizationId;
            }
        }

        return $currentOrganizationIsActive ? $currentOrganizationId : $activeOrganizationIds[0];
    }

    private function userCanAccessAdminSystem(User $user): bool
    {
        try {
            return app(AuthorizationService::class)->can($user, 'admin.access', [
                'context_type' => 'system',
            ]);
        } catch (\Throwable $exception) {
            Log::warning('auth.system_admin_access_check_failed', [
                'user_id' => $user->id,
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }

    private function userCanAccessAdminOrganization(User $user, int $organizationId): bool
    {
        try {
            return app(AuthorizationService::class)->can($user, 'admin.access', [
                'context_type' => 'organization',
                'organization_id' => $organizationId,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('auth.organization_admin_access_check_failed', [
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }

}
