<?php

namespace App\Services\Auth;

use App\DTOs\Auth\LoginDTO;
use App\DTOs\Auth\RegisterDTO;
use App\Models\User;
use App\Repositories\Interfaces\OrganizationRepositoryInterface;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\Services\LogService;
use App\Services\PerformanceMonitor;
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

    /**
     * Конструктор сервиса аутентификации.
     *
     * @param UserRepositoryInterface $userRepository
     * @param OrganizationRepositoryInterface $organizationRepository
     */
    public function __construct(
        UserRepositoryInterface $userRepository,
        OrganizationRepositoryInterface $organizationRepository
    ) {
        $this->userRepository = $userRepository;
        $this->organizationRepository = $organizationRepository;
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

                    // Загружаем отношения с ролями для корректной работы Gate
                    $user->load('roles');
                    Log::info('[JwtAuthService] User roles loaded.', [
                        'user_id' => $user->id,
                        'roles_count' => $user->roles->count()
                    ]);
                    
                    // Если ролей нет вообще, проверяем и восстанавливаем роль владельца
                    if ($user->roles->count() === 0) {
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
                            
                            // Находим роль Owner
                            $ownerRole = \App\Models\Role::where('slug', \App\Models\Role::ROLE_OWNER)->first();
                            if ($ownerRole) {
                                // Проверяем, что связь в pivot таблице отсутствует
                                $roleExists = DB::table('role_user')
                                    ->where('user_id', $user->id)
                                    ->where('role_id', $ownerRole->id)
                                    ->where('organization_id', $firstOrg->id)
                                    ->exists();
                                    
                                if (!$roleExists) {
                                    // Создаем связь
                                    $user->roles()->attach($ownerRole->id, ['organization_id' => $firstOrg->id]);
                                    Log::info('[JwtAuthService] Fixed: Owner role assigned.', [
                                        'user_id' => $user->id,
                                        'organization_id' => $firstOrg->id,
                                        'role_id' => $ownerRole->id
                                    ]);
                                    
                                    // Перезагружаем отношения
                                    $user->load('roles');
                                }
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
                // Формируем данные для создания организации
                $orgData = [
                    'name' => $orgName,
                    'owner_id' => $user->id
                ];
                
                // Добавляем дополнительные поля из DTO, если они есть
                $orgFields = [
                    'legal_name', 'tax_number', 'registration_number', 
                    'phone', 'email', 'address', 'city', 
                    'postal_code', 'country'
                ];
                
                foreach ($orgFields as $field) {
                    $dtoField = 'organization' . ucfirst($field);
                    if (isset($registerDTO->$dtoField)) {
                        $orgData[$field] = $registerDTO->$dtoField;
                    }
                }
                
                Log::info('[JwtAuthService] Organization data prepared', [
                    'org_name' => $orgData['name'],
                    'owner_id' => $orgData['owner_id']
                ]);
                
                try {
                    // Создаем организацию
                    $organization = $this->organizationRepository->create($orgData);
                    Log::info('[JwtAuthService] Organization created', [
                        'org_id' => $organization->id ?? 'Failed to get ID',
                        'name' => $organization->name
                    ]);
                    // Привязываем пользователя к организации
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
                        'current_org_id' => $organization->current_organization_id ?? $organization->id
                    ]);

                    // Назначаем пользователю роль владельца организации (organization_owner)
                    $ownerRole = \App\Models\Role::where('slug', \App\Models\Role::ROLE_OWNER)->first();
                    if ($ownerRole) {
                        $alreadyHas = $user->roles()
                            ->where('roles.id', $ownerRole->id)
                            ->wherePivot('organization_id', $organization->id)
                            ->exists();
                        if (!$alreadyHas) {
                            $user->roles()->attach($ownerRole->id, ['organization_id' => $organization->id]);
                            Log::info('[JwtAuthService] Owner role attached to user after registration.', [
                                'user_id' => $user->id,
                                'organization_id' => $organization->id,
                                'role_id' => $ownerRole->id,
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('[JwtAuthService] Failed to create organization', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    throw $e; // Пробрасываем исключение для обработки во внешнем catch
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

            // Отправляем приветственное письмо. Пытаемся через queue; если очередь недоступна — шлём синхронно.
            try {
                Mail::to($user->email)->queue(new UserWelcomeMail($user));
            } catch (\Throwable $mailEx) {
                Log::warning('[JwtAuthService] Queue welcome mail failed, fallback to sync send', [
                    'user_id' => $user->id,
                    'error' => $mailEx->getMessage(),
                ]);
                try {
                    Mail::to($user->email)->send(new UserWelcomeMail($user));
                } catch (\Throwable $mailSyncEx) {
                    Log::error('[JwtAuthService] Failed to send welcome email synchronously', [
                        'user_id' => $user->id,
                        'error' => $mailSyncEx->getMessage(),
                    ]);
                }
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
} 