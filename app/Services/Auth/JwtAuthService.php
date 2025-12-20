<?php

namespace App\Services\Auth;

use App\DTOs\Auth\LoginDTO;
use App\DTOs\Auth\RegisterDTO;
use App\Models\User;
use App\Models\Organization;
use App\Repositories\Interfaces\OrganizationRepositoryInterface;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\Services\LogService;
use App\Services\PerformanceMonitor;
use App\Interfaces\Billing\BalanceServiceInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
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

    /**
     * Конструктор сервиса аутентификации.
     *
     * @param UserRepositoryInterface $userRepository
     * @param OrganizationRepositoryInterface $organizationRepository
     * @param BalanceServiceInterface $balanceService
     */
    public function __construct(
        UserRepositoryInterface $userRepository,
        OrganizationRepositoryInterface $organizationRepository,
        BalanceServiceInterface $balanceService
    ) {
        $this->userRepository = $userRepository;
        $this->organizationRepository = $organizationRepository;
        $this->balanceService = $balanceService;
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
        Log::info('[JwtAuthService] authenticate method entered.');
        // Убираем PerformanceMonitor временно для диагностики
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
                        return ['success' => false, 'message' => 'Неверный email или пароль', 'status_code' => 401];
                    }
                    Log::info('[JwtAuthService] Auth::validate passed. Before Auth::getLastAttempted.');

                    /** @var User $user */
                    $user = Auth::getLastAttempted();
                    Log::info('[JwtAuthService] User retrieved.', ['user_id' => $user?->id]);
                    $user->update([
                        'last_login_at' => now(),
                        'last_login_ip' => request()->ip(),
                    ]);
                    Log::info('[JwtAuthService] User last login updated.');

                    // Загружаем отношения с новой системой авторизации (с fallback)
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
                        // Продолжаем без проверки ролей, пока не созданы таблицы новой системы
                    }
                    
                    // Если назначений ролей нет вообще, проверяем и восстанавливаем роль владельца
                    if ($assignmentsCount === 0) {
                        Log::warning('[JwtAuthService] User has no roles, checking organizations.', [
                            'user_id' => $user->id,
                        ]);
                        
                        // Проверяем, есть ли у пользователя организации
                        $userOrganizations = $user->organizations()->get();
                        if ($userOrganizations->isNotEmpty()) {
                            Log::info('[JwtAuthService] User has organizations but no roles. Fixing role for first organization.', [
                                'user_id' => $user->id,
                                'organizations_count' => $userOrganizations->count()
                            ]);
                            
                            // Берем первую организацию и назначаем роль владельца
                            $firstOrg = $userOrganizations->first();
                            
                            // Назначаем роль владельца через новую систему авторизации
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
                                // Не критичная ошибка - роли будут назначены после создания таблиц
                            }
                        }
                    }

                    // Определяем ID организации (пока просто первая)
                    $userOrganizations = $user->organizations()->pluck('organizations.id')->toArray(); // <-- Указываем таблицу organizations.id
                    Log::info('[JwtAuthService] User organizations IDs.', ['user_id' => $user->id, 'organization_ids' => $userOrganizations]); // Логируем все организации
                    
                    // Явно выбираем ID организации перед first()
                    $organizationId = $user->organizations()->select('organizations.id')->first()?->id; // <-- Указываем таблицу и выбираем organisations.id
                    Log::info('[JwtAuthService] Organization ID determined for token (using first).', ['user_id' => $user->id, 'selected_org_id' => $organizationId]); // Логируем выбранную

                    // Устанавливаем current_organization_id для объекта User, который вернется в контроллер
                    // Это важно для корректной работы Gate в контроллере
                    if ($organizationId && $user->current_organization_id !== $organizationId) {
                         $user->current_organization_id = $organizationId;
                         $user->save();
                         Log::info('[JwtAuthService] User object\'s current_organization_id set (was different).', ['user_id' => $user->id, 'org_id' => $organizationId]);
                    } elseif (!$user->current_organization_id && $organizationId) {
                        $user->current_organization_id = $organizationId;
                        $user->save();
                         Log::info('[JwtAuthService] User object\'s current_organization_id set (was null).', ['user_id' => $user->id, 'org_id' => $organizationId]);
                    } else {
                         Log::info('[JwtAuthService] User object\'s current_organization_id not changed.', ['user_id' => $user->id, 'existing_org_id' => $user->current_organization_id, 'determined_org_id' => $organizationId]); // Логируем, если не меняли
                    }

                    // Генерируем токен
                    $customClaims = ['organization_id' => $organizationId];
                    $token = JWTAuth::claims($customClaims)->fromUser($user);
                    Log::info('[JwtAuthService] JWT token generated.');

                    LogService::authLog('login_success', array_merge($logContext, ['user_id' => $user->id, 'organization_id' => $organizationId]));
                    // Возвращаем $user с установленным (надеемся) current_organization_id
                    return ['success' => true, 'token' => $token, 'user' => $user, 'status_code' => 200];

                } catch (JWTException $e) {
                    Log::error('[JwtAuthService] JWTException caught.', ['error' => $e->getMessage()]);
                    $errorContext = array_merge($logContext, [
                        'action' => 'login_jwt_error',
                        'guard' => $guard,
                        'error_message' => $e->getMessage(),
                        'error_code' => $e->getCode()
                    ]);
                    if (isset($loginDTO)) { try { $errorContext['email'] = $loginDTO->email; } catch (\Exception $ex) {} }
                    
                    LogService::exception($e, $errorContext);
                    
                    return [
                        'success' => false,
                        'message' => 'Ошибка создания токена JWT',
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
                if (isset($loginDTO)) { try { $errorContext['email'] = $loginDTO->email; } catch (\Exception $ex) {} }

                // Заменяем LogService::exception на стандартный Log::error с полным стеком
                // Log::info('[JwtAuthService] Before calling LogService::exception in outer catch.');
                // LogService::exception($e, $errorContext); 
                // Log::info('[JwtAuthService] After calling LogService::exception in outer catch.');
                Log::error('[JwtAuthService] Unexpected Authentication Error', [
                    'context' => $errorContext,
                    'exception_class' => get_class($e),
                    'exception_message' => $e->getMessage(),
                    'exception_trace' => $e->getTraceAsString() // Логируем полный стек
                ]);

                return [
                    'success' => false,
                    'message' => 'Внутренняя ошибка сервера при аутентификации.',
                    'status_code' => 500
                ];
            }
        // }); // Конец PerformanceMonitor
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
                    'message' => 'Пользователь не аутентифицирован',
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
                'message' => 'Токен просрочен',
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
                'message' => 'Недействительный токен',
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
                'message' => 'Токен отсутствует',
                'status_code' => 401
            ];
        }
    }

    /**
     * Получение ID текущей организации из JWT токена.
     *
     * @return int|null ID организации или null, если токен не содержит organization_id.
     */
    public function getCurrentOrganizationId(): ?int
    {
        try {
            $payload = JWTAuth::parseToken()->getPayload();
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
            $token = Auth::refresh();
            
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
                'message' => 'Токен просрочен и не может быть обновлен',
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
                'message' => 'Ошибка обновления токена',
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
            $token = Auth::getToken();
            
            if ($token) {
                JWTAuth::invalidate($token);
                Auth::logout(true); // true - очистить пользовательские данные
                
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
                    'message' => 'Выход выполнен успешно', // Это сообщение не будет видно при вызове с Gate::denies
                    'status_code' => 200
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Токен не найден',
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
                'message' => 'Ошибка при выходе: ' . $e->getMessage(),
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
    public function register(RegisterDTO $registerDTO): array
    {
        Log::info('[JwtAuthService] Register method called', [
            'email' => $registerDTO->email ?? 'N/A'
        ]);
        
        DB::beginTransaction(); // Используем транзакцию
        try {
            // Получаем данные пользователя
            $userData = $registerDTO->getUserData(); // Используем getUserData()
            
            Log::info('[JwtAuthService] User data prepared', [
                'email' => $userData['email'] ?? 'N/A',
                'name' => $userData['name'] ?? 'N/A'
            ]);
            
            // Проверяем, не существует ли уже пользователь с таким email
            $existingUser = $this->userRepository->findByEmail($userData['email']);
            if ($existingUser) {
                Log::warning('[JwtAuthService] User already exists with this email', [
                    'email' => $userData['email'],
                    'user_id' => $existingUser->id
                ]);
                DB::rollBack(); // откатываем транзакцию
                return ['success' => false, 'message' => 'Пользователь с таким email уже существует', 'status_code' => 422];
            }

            // Создаем пользователя
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
                throw $e; // Пробрасываем исключение для обработки во внешнем catch
            }
            
            $organization = null;

            // Создаем организацию, если имя передано
            $orgName = $registerDTO->organizationName; // Используем магический __get
            Log::info('[JwtAuthService] Organization name from DTO', [
                'organization_name' => $orgName
            ]);
            
            if (!empty($orgName)) {
                // Получаем данные организации из DTO
                $orgData = $registerDTO->getOrganizationData();
                
                // Добавляем owner_id
                $orgData['owner_id'] = $user->id;
                
                Log::info('[JwtAuthService] Organization data prepared', [
                    'org_name' => $orgData['name'],
                    'owner_id' => $orgData['owner_id'],
                    'legal_name' => $orgData['legal_name'] ?? 'не указано',
                    'tax_number' => $orgData['tax_number'] ?? 'не указано',
                    'address' => $orgData['address'] ?? 'не указано'
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
                                'message' => 'Организация с таким ИНН уже зарегистрирована в системе. Если вы являетесь сотрудником этой организации, попросите владельца добавить вас в команду.', 
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
                            'message' => 'Организация с таким ИНН уже зарегистрирована в системе. Если вы являетесь сотрудником этой организации, попросите владельца добавить вас в команду.', 
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

            // Генерируем JWT токен для пользователя
            $token = null;
            try {
                if ($organization) {
                    $customClaims = ['organization_id' => $organization->id];
                    $token = JWTAuth::claims($customClaims)->fromUser($user);
                } else {
                    $token = JWTAuth::fromUser($user);
                }
                Log::info('[JwtAuthService] JWT token generated');
            } catch (\Exception $e) {
                Log::error('[JwtAuthService] Failed to generate JWT token', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }

            // Фиксируем транзакцию
            DB::commit();

            // АВТОМАТИЧЕСКАЯ ВЕРИФИКАЦИЯ И СИНХРОНИЗАЦИЯ (вне транзакции)
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
                    
                    // Синхронизируем только не синхронизированных подрядчиков
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
                    
                    // Для уведомлений ищем ВСЕХ подрядчиков с таким ИНН (включая уже синхронизированных)
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
                            
                            Log::channel('security')->info('[JwtAuthService] ✅ Customer notifications SUCCESSFULLY sent', [
                                'organization_id' => $organization->id,
                                'organization_name' => $organization->name,
                                'customers_notified' => $allContractorsByInn->count(),
                                'verification_score' => $verificationResult['verification_score']
                            ]);
                        } catch (\Exception $notifEx) {
                            Log::channel('security')->critical('[JwtAuthService] ❌ CRITICAL: Failed to send customer notifications', [
                                'organization_id' => $organization->id,
                                'organization_name' => $organization->name,
                                'tax_number' => $organization->tax_number,
                                'contractors_count' => $allContractorsByInn->count(),
                                'error' => $notifEx->getMessage(),
                                'trace' => $notifEx->getTraceAsString()
                            ]);
                            // НЕ прерываем регистрацию, но записываем критическую ошибку
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
                    // Не прерываем регистрацию - верификация не критична
                }
            }

            // Отправляем письмо для верификации email
            try {
                $user->sendEmailVerificationNotification();
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

            // Верифицируем, что пользователь действительно сохранен
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
            DB::rollBack(); // Откатываем транзакцию
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
                'message' => 'Ошибка при регистрации пользователя: ' . $e->getMessage(), 
                'status_code' => 500
            ];
        }
    }

    /**
     * Выдача тестового баланса при регистрации (если включен тестовый режим)
     * 
     * @param \App\Models\Organization $organization
     * @param User $user
     * @return void
     */
    protected function grantTestingBalanceIfEnabled(\App\Models\Organization $organization, User $user): void
    {
        // Проверяем, включен ли тестовый режим
        if (!config('billing.testing.enabled', false)) {
            Log::debug('[JwtAuthService] Testing mode is disabled, skipping initial balance grant', [
                'organization_id' => $organization->id,
            ]);
            return;
        }

        try {
            $initialBalance = config('billing.testing.initial_balance', 0);
            $description = config('billing.testing.description', 'Тестовый баланс при регистрации');
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

            // Выдаем баланс через BalanceService
            $orgBalance = $this->balanceService->creditBalance(
                $organization,
                $initialBalance,
                $description,
                null, // payment = null (это не пополнение через платеж)
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

            Log::info('[JwtAuthService] ✅ Testing balance granted successfully', [
                'organization_id' => $organization->id,
                'amount_rubles' => round($initialBalance / 100, 2),
                'balance_after_rubles' => round($orgBalance->balance / 100, 2),
            ]);

        } catch (\Exception $e) {
            // Не прерываем регистрацию, если не удалось выдать тестовый баланс
            Log::error('[JwtAuthService] Failed to grant testing balance', [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
} 